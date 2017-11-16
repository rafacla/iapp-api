<?php

class enviarEmail
{
	protected static $smtp_host;
	protected static $smtp_port = "587";
	protected static $user;
	protected static $pass;
	protected static $remetente_email = "nao-responda@rafacla.com";
	protected static $remetente_nome = "Rafael APP";

	protected static $mail;
	
	public function __construct($destinatario,$assunto,$template,$variaveis) {
		// Include the PHPMailer class
		include('phpmailer/class.phpmailer.php');
		$ini_array = parse_ini_file("config.ini", true);
		
		self::$smtp_host = $ini_array['smtp_host'];
		self::$user = $ini_array['smtp_user'];
		self::$pass = $ini_array['smtp_password'];
	
		// Setup PHPMailer
		self::$mail = new PHPMailer();
		self::$mail->IsSMTP();
		self::$mail->SMTPDebug = 2;
		// This is the SMTP mail server
		self::$mail->Host = self::$smtp_host;
		self::$mail->Port = self::$smtp_port;
		self::$mail->SMTPAutoTLS = false;
		// Remove these next 3 lines if you dont need SMTP authentication
		self::$mail->SMTPAuth = true;
		self::$mail->Username = self::$user;
		self::$mail->Password = self::$pass;
		//self::$mail->SMTPSecure = 'ssl';
		
		// Set who the email is coming from
		self::$mail->SetFrom(self::$remetente_email, self::$remetente_nome);
				
		// Set who the email is sending to
		self::$mail->AddAddress($destinatario);

		// Set the subject
		self::$mail->Subject = $assunto;
		
		// Retrieve the email template required
		$message = file_get_contents('mail_templates/'.$template.'.html');

		foreach ($variaveis as $key => $value) {
			$message = str_replace('%'.$key.'%', $value, $message);
		}		
		
		//Set the message
		self::$mail->MsgHTML($message);
		//self::$mail->AltBody(strip_tags($message));
	}
	
	public function enviar() {
		// Send the email
		if(!self::$mail->Send()) {
			echo("Mailer Error: " . self::$mail->ErrorInfo);
			
			return false;
		}
		return true;
	}
}