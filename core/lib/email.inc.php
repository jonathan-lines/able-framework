<?php

//
//   $em = new Email();
//
//   // software name for X-Mailer
//   $em = new Email('Super Awesome Mailer');
//
//   $em->set_to_email('destination@domain.com');
//   $em->set_subject('example subject');
//   $em->set_message('this is the message body');
//
//   // enable html (and plaintext) versions
//   $em->enable_html();
//
//   // optional name of sender and receiver
//   $em->set_to_name('John Smith');
//   $em->set_from_name('Sender Name');
//
//   // add one (or more) people to cc
//   $em->add_cc('another@domain.com');
//
//   // add attachment (file to read, filename in email)
//   $em->add_attachment('dir/a/b/file.dat', 'file.dat');
//
//   // add a raw email header
//   $em->add_header('X-Fruit', 'banana');
//
//   // send email (confirm)
//   $em->send(true);
//
//   // returns raw email
//   $em->send(false);
//

class Email
{
   private $to_email;
   private $to_name;
   private $from_email;
   private $from_name;
   private $subject;
   private $message;
   private $headers;
   private $attachments;
   private $cc;
   private $html; 
   private $mailer;
   
   public function __construct($mailer = false)
   {
      $this->mailer = $mailer;
      $this->attachments = array();
      $this->headers = array();      
      $this->cc = array();      
      $this->html = false;
   }
   
   public function set_to_email($val)
   {
      $this->to_email = $val;
   }
   
   public function set_to_name($val)
   {
      $this->to_name = $val;
   }
   
   public function set_from_email($val)
   {
      $this->from_email = $val;
   }
   
   public function set_from_name($val)
   {
      $this->from_name = $val;
   }
   
   public function set_subject($val)
   {
      $this->subject = $val;
   }
   
   public function set_message($val)
   {
      $this->message = $val;
   }
   
   public function set_header($name, $value)
   {
      $this->headers[$name] = $value;
   }
   
   public function add_attachment($file, $name)
   {
      $this->attachments[] = array($file, $name);
   }
       
   public function add_cc($val)
   {
      $this->cc[] = $val;
   }
   
   public function enable_html()
   {
      $this->html = true;
   }
   
   // fetch a raw header
   private static function raw_header($name, $value)
   {
      return sprintf('%s: %s', $name, $value);
   }
   
   // fetch all raw headers line seperated
   private static function raw_header_lines($headers)
   {
      // no header lines
      if (count($headers) == 0)
        return '';
        
      $raw_headers = array();         
      foreach($headers as $name => $value)
        $raw_headers[] = self::raw_header($name, $value);
      
      $header_lines = implode(self::NL, $raw_headers);
      $header_lines = $header_lines . self::NL;
      
      return $header_lines;
   }
   
   // fetch a raw boundary   
   private static function raw_bound($bound, $close = false)
   {
      return sprintf(($close ? '--%s--' : '--%s'), $bound);
   }
   
   // handle html content
   // return headers
   private function compose_alt($headers = array())
   {
      if ($this->html)   
      {
         $bound            = md5(microtime());
         $html_message     = $this->message;
         $plain_message    = wordwrap(strip_tags($this->message), 70);
         $header_lines     = self::raw_header_lines($headers);
                  
         $this->message = implode(self::NL, array(
            self::raw_bound($bound), 
            self::raw_header(self::CONTENT_TYPE_HEADER, 
               self::PLAIN_HEADER_VALUE), 
            $header_lines,
            $plain_message,
            self::raw_bound($bound),
            self::raw_header(self::CONTENT_TYPE_HEADER,
               self::HTML_HEADER_VALUE),
            $header_lines,
            $html_message,
            self::raw_bound($bound, true),
         ));
         
         return array(
            // alternative header
            self::CONTENT_TYPE_HEADER =>
               sprintf(self::ALT_HEADER_VALUE, $bound),
         );
      }
      
      $this->message = wordwrap($this->message, 70);
      
      return array_merge($headers, array(
         // plain text header
         self::CONTENT_TYPE_HEADER =>
            self::PLAIN_HEADER_VALUE,
      ));
   }
   
   // handle attachments
   // return any headers
   private function compose_mixed($headers = array())
   {
      if (count($this->attachments) > 0)
      {
         $bound         = md5(microtime());
         $message       = $this->message;
         $header_lines  = self::raw_header_lines($headers);
         
         // actual message content
         $this->message = implode(self::NL, array(
            self::raw_bound($bound), 
            $header_lines,
            $message,
         ));
         
         foreach ($this->attachments as $attach)
         {
            $file = $attach[0];
            $name = $attach[1];
            
            if (!is_file($file)) 
              continue;
              
            $mime = mime_content_type($file);
            $data = file_get_contents($file);
            $b64c = chunk_split(base64_encode($data)); 
            
            // each file one a time
            $this->message = implode(self::NL, array(
               $this->message,
               self::raw_bound($bound), 
               // attachment
               self::raw_header(self::CONTENT_TYPE_HEADER,
                  sprintf(self::ATTACH_HEADER_VALUE, $mime, $name)),
               // content encoding
               self::raw_header(self::ENCODING_HEADER, 
                  self::ENCODING_HEADER_VALUE),
               // content disposition
               self::raw_header(self::DISPOSITION_HEADER, 
                  self::DISPOSITION_HEADER_VALUE),
               '',
               $b64c,
            ));
         }
         
         // closing bound
         $this->message = implode(self::NL, array(
            $this->message,
            self::raw_bound($bound, true),
         ));
         
         return array(
            // alternative header
            self::CONTENT_TYPE_HEADER =>
               sprintf(self::MIXED_HEADER_VALUE, $bound),
         );
      }
      
      return $headers;
   }
   
   public function send($real_send = false)
   {
      $headers = array();
      $headers = $this->compose_alt($headers);
      $headers = $this->compose_mixed($headers);
      
      foreach($headers as $name => $value)
        $this->set_header($name, $value);
        
      $header_lines = self::raw_header_lines($this->headers);
      
      $to = $this->to_email;
      $from = $this->from_email;
      $cc = implode(',', $this->cc);
      
      if (isset($this->to_name))
        $to = sprintf('%s <%s>', 
           $this->to_name, $this->to_email);
           
      if (isset($this->from_name))
        $from = sprintf('%s <%s>', 
           $this->from_name, $this->from_email);
           
      $header_lines = implode(self::NL, array(
         // mailer name
         self::raw_header(self::MAILER_HEADER,
            ($this->mailer ? $this->mailer : self::MAILER_HEADER_VALUE)),
         // mime version
         self::raw_header(self::MIME_HEADER,
            self::MIME_HEADER_VALUE),
         // from address
         self::raw_header(self::FROM_HEADER, $from),
         // cc addresses
         self::raw_header(self::CC_HEADER, $cc),
         // others
         $header_lines,
      ));
      
      if ($real_send)
      {
         // actually send the email and return result
         return mail($to, $this->subject, $this->message, $header_lines);
      }
      else
      {
         // return the email in plain text
         return implode(self::NL, array(
            sprintf('To: %s', $to),
            sprintf('Subject: %s', $this->subject),
            $header_lines,
            $this->message,
         ));
      }
   }

   const MAILER_HEADER              = 'X-Mailer';
   const MAILER_HEADER_VALUE        = 'PHP Mail';

   const MIME_HEADER                = 'MIME-Version';
   const MIME_HEADER_VALUE          = '1.0';
   const CC_HEADER                  = 'CC';   
   const FROM_HEADER                = 'From';   
   const CONTENT_TYPE_HEADER        = 'Content-Type';
   const ATTACH_HEADER_VALUE        = '%s; name="%s"';
   const ENCODING_HEADER            = 'Content-Transfer-Encoding';
   const ENCODING_HEADER_VALUE      = 'base64';
   const DISPOSITION_HEADER         = 'Content-Disposition';
   const DISPOSITION_HEADER_VALUE   = 'attachment';
   const MIXED_HEADER_VALUE         = 'multipart/mixed; boundary="%s"';
   const ALT_HEADER_VALUE           = 'multipart/alternative; boundary="%s"';
   const PLAIN_HEADER_VALUE         = 'text/plain; charset="utf-8"';
   const HTML_HEADER_VALUE          = 'text/html; charset="utf-8"';
   const NL                         = "\r\n"; // new line
}

?>
