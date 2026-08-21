<?php
/*
$config['email_agent'] = 'default';
$config['sender_email'] = 'no-reply@domain.com';
$config['email_sender_name'] = '';

$config['sendgrid_key'] = '';

$config['smtp_host'] = 'smtp.gmail.com';
$config['smtp_port'] = '587';
$config['smtp_username'] = '';
$config['smtp_password'] = '';
$config['smtp_secure'] = 'tls';

--------------------------------------------------------------------------------
1. init:
$myEmailSender = new \App\Libs\mailer\EmailSender();

OR

$myEmailSender = new \App\Libs\mailer\EmailSender('smtp.example.com', 587, 'your_email@example.com', 'your_password', 'tls');

--------------------------------------------------------------------------------
2. sender:
$myEmailSender->setSender('demo@example.com');

OR

$myEmailSender->setSender('demo@example.com', 'demo');

--------------------------------------------------------------------------------
3. to:
$myEmailSender->setRecipients('test@example.com');

OR

$myEmailSender->setRecipients('test@example.com;test_2@example.com');

OR

$myEmailSender->setRecipients(['test_1@example.com', 'test_2@example.com']);

--------------------------------------------------------------------------------
4. send out:
$myEmailSender->sendOut('Here is the subject', 'This is the HTML message body <b>in bold!</b>');

OR

$myEmailSender->sendOut('Here is the subject', 'This is the HTML message body <b>in bold!</b>', '/path/to/file1.zip');

OR

$myEmailSender->sendOut('Here is the subject', 'This is the HTML message body <b>in bold!</b>', ['/path/to/file1.zip', '/path/to/file2.zip']);

*/
namespace App\Libs\mailer;

class EmailSender {
    private $_recipients = [];
    
    private $_senderEmail = '';
    private $_senderName = ''; 
    
    private $_smtpServer = '';
    private $_smtpPort = '';
    private $_smtpUser = '';
    private $_smtpPassword = '';
    private $_smtpSecure = 'ssl';
    
    private $_agent = '';
    private $_agent_key = '';

    public function __construct($smtpServer = '', $smtpPort = '', $smtpUser = '', $smtpPassword = '', $smtpSecure = 'ssl') {
        $this->_smtpServer = $smtpServer;
        $this->_smtpPort = $smtpPort;
        $this->_smtpUser = $smtpUser;
        $this->_smtpPassword = $smtpPassword;
        $this->_smtpSecure = $smtpSecure;
    }

    public function setSMTP($smtpServer, $smtpPort, $smtpUser, $smtpPassword, $smtpSecure = 'ssl') {
        $this->_smtpServer = $smtpServer;
        $this->_smtpPort = $smtpPort;
        $this->_smtpUser = $smtpUser;
        $this->_smtpPassword = $smtpPassword;
        $this->_smtpSecure = $smtpSecure;
        return $this;
    }
    
    public function setAgent($name = '', $key = '') {
        $this->_agent = strtolower(trim($name));
        $this->_agent_key = $key;
    }

    public function setSender($email, $name = '') {
        $this->_senderEmail = $email;
        $this->_senderName = $name;
        return $this;
    }
    
    public function setRecipients($toEmail = '') {
        if(!empty($toEmail)){
            if(is_array($toEmail)) {
                $this->_recipients = array_filter(array_unique($toEmail));
            }
            else {
                $toEmail = explode(PHP_EOL, str_replace(';', PHP_EOL, $toEmail));
                $this->_recipients = array_filter(array_unique($toEmail));
            }
            if(!empty($this->_recipients)) {
                foreach ($this->_recipients as $recipientKey => $recipient) {
                    $recipient = trim($recipient);
                    if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                        $this->_recipients[$recipientKey] = $recipient;
                    }
                    else {
                        unset($this->_recipients[$recipientKey]);
                    }
                }
            }
        }
        return $this;
    }

    public function sendOut($subject, $content, $attachment = [], $debug = false) {
        if($this->_agent == 'sendgrid') {
            return $this->ViaSendGrid($subject, $content, $attachment, $debug);
        }
        else {
            return $this->ViaSMTP($subject, $content, $attachment, $debug);
        }
    }
    
    private function ViaSMTP($subject, $content, $attachment = [], $debug = false) {
        try {
            if(!empty($this->_recipients) && !empty($subject) && !empty($content)) {
                require_once('Exception.php');
                require_once('PHPMailer.php');
                if($this->_agent == 'smtp') {
                    if (!empty($this->_smtpServer) && !empty($this->_smtpPort) && !empty($this->_smtpUser) && !empty($this->_smtpPassword)) {
                        require_once('SMTP.php');
                    }
                }
                
                $mail = new \PHPMailer\PHPMailer\PHPMailer(); 
                $mail->CharSet = 'UTF-8';

                // enable smtp if need
                if($this->_agent == 'smtp') {
                    if (!empty($this->_smtpServer) && !empty($this->_smtpPort) && !empty($this->_smtpUser) && !empty($this->_smtpPassword)) {
                        $mail->IsSMTP(); 
                        $mail->Mailer       =   'smtp';
                        $mail->SMTPDebug    =   $debug;  
                        $mail->SMTPAuth     =   TRUE;
                        $mail->Host         =   $this->_smtpServer;
                        $mail->Port         =   $this->_smtpPort;
                        $mail->Username     =   $this->_smtpUser;
                        $mail->Password     =   $this->_smtpPassword;
                        $mail->SMTPSecure   =   strtoupper($this->_smtpSecure);
                    }
                }

                $mail->IsHTML(true);
                $mail->Subject = $subject;
                
                // extract and embed images
                preg_match_all('/<img[^>]+src="((?!https|http)[^">]+)"/i', $content, $allMatches);
                if(!empty($allMatches) && !empty($allMatches[1])) {
                    $imagePaths = $allMatches[1];
                    // Replace src with cid in the body
                    foreach ($imagePaths as $index => $imagePath) {
                        $cid = 'image' . $index;
                        $content = str_replace($imagePath, ('cid:'.$cid), $content);
                        $mail->addEmbeddedImage($imagePath, $cid);
                    }
                }
                $mail->MsgHTML(('<div>'.$content.'</div>')); 
                
                if(!empty($attachment)) {
                    if(is_string($attachment)) {
                        $attachment = [$attachment];
                    }
                    foreach ($attachment as $file) {
                        if(file_exists($file)) {
                            $mail->addAttachment($file);
                        }
                    }
                }

                foreach ($this->_recipients as $recipient) {
                    $mail->AddAddress(trim($recipient));
                }
                $mail->SetFrom($this->_senderEmail, $this->_senderName);
                return $mail->Send();
            }
        } catch (Exception $ex) {
            throw new Exception('Mail sending failed: ' . $ex->getMessage());
        }
        
        return false;
    }
    
    private function ViaSendGrid($subject, $content, $attachment = [], $debug = false) {
        try {
            if(!empty($this->_recipients) && !empty($subject) && !empty($content)) {
                $toEmailList = [];
                foreach ($this->_recipients as $recipient) {
                    $toEmailList[] = ['email' => $recipient];
                }
                
                // Initialize the array to hold embedded images
                $embeddedImages = [];

                // Match image paths within the TinyMCE content
                preg_match_all('/<img[^>]+src=["\'](.*?)["\']/i', $content, $matches);
                foreach ($matches[1] as $index => $path) {
                    // Check if the path is valid and file exists
                    if (file_exists($path)) {
                        // Generate a unique CID for each image
                        $cid = 'image' . $index;
                        // Replace the path in the HTML content with the CID
                        $content = str_replace($path, 'cid:' . $cid, $content);
                        // Add the image to the embedded images array
                        $embeddedImages[$cid] = $path;
                    }
                }

                // Prepare the email data structure
                $emailData = 
                [
                    'personalizations'  => 
                    [
                        [
                            'to'        =>  $toEmailList,
                            'subject'   =>  $subject
                        ]
                    ],
                    'from' => 
                    [
                        'email'         =>  $this->_senderEmail,
                        'name'          =>  $this->_senderName
                    ],
                    'content'           => 
                    [
                        [
                            'type'      =>  'text/html',
                            'value'     =>  $content
                        ]
                    ]
                ];
                
                // Add embedded images as inline attachments
                foreach ($embeddedImages as $cid => $file) {
                    $emailData['attachments'][] = [
                        'content' => base64_encode(file_get_contents($file)),
                        'type' => mime_content_type($file),
                        'filename' => basename($file),
                        'disposition' => 'inline',
                        'content_id' => $cid
                    ];
                }
                
                // Handle standard attachments
                if (!empty($attachment)) {
                    if (is_string($attachment)) {
                        $attachment = [$attachment];
                    }
                    foreach ($attachment as $file) {
                        if (file_exists($file)) {
                            $emailData['attachments'][] = [
                                'content' => base64_encode(file_get_contents($file)),
                                'type' => mime_content_type($file),
                                'filename' => basename($file),
                                'disposition' => 'attachment'
                            ];
                        }
                    }
                }
      
                // Send email via SendGrid API
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'https://api.sendgrid.com/v3/mail/send');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($emailData));
                $headers = [
                    'Authorization: Bearer '.$this->_agent_key,
                    'Content-Type: application/json'
                ];
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                $response = curl_exec($ch);

                if (curl_errno($ch)) {
                    throw new Exception('Error:' . curl_error($ch));
                } else {
                    $result = [];
                    if(is_array(json_decode($response, true))) {
                        $result = array_merge($result, json_decode($response, true));
                    }

                    if(!empty($debug)) {
                        echo '<pre>';
                        print_r($result);
                        echo '</pre>';
                    }
                }
                curl_close($ch);
                if(empty($result['errors'])) {
                    return true;
                }
            }
        } catch (Exception $ex) {
            throw new Exception('Mail sending failed: ' . $ex->getMessage());
        }
        
        return false;
    }
}