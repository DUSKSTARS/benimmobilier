<?php
/*
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // Inclure PHPMailer
// Vérifiez si le formulaire a été soumis

$nom = "Bruno";
$email = "brunoadenhouessou@gmail.com";
$objet = "Salutation";
$message = "CCCCCCCCCCCCCCCCC";
   
// Configuration de PHPMailer
$mail = new PHPMailer(true);
try {
    // Paramètres SMTP
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'grandpremier76@gmail.com'; // Remplacez par votre adresse Gmail
    $mail->Password = 'stcovjlgsfbfniev'; // Remplacez par le mot de passe de votre compte Gmail
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587; // Le port SMTP de votre fournisseur de messagerie

    // Destinataire et expéditeur
    $mail->setFrom('grandpremier76@gmail.com', 'Audace Company');
    $mail->addAddress($email, "MM/Mme $nom");

    // Contenu de l'e-mail
    $mail->isHTML(true);
    $mail->Subject = 'Commande sur ...';

    // Définissez le contenu de l'e-mail
    $mail->Body =$message;
        // Envoyer l'e-mail
    $mail->send();
    
    echo 'L\'e-mail a été envoyé avec succès.';
    die();
}
catch (Exception $e) {
    echo 'L\'envoi de l\'e-mail a échoué. Erreur : ' . $mail->ErrorInfo;die();
}
*/

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; // Inclure PHPMailer
// Vérifiez si le formulaire a été soumis

$nom = "Audace";
$email = "grandpremier76@gmail.com";
$objet = "Salutation";
$message = "CCCCCCCCCCCCCCCCC";
   
// Configuration de PHPMailer
$mail = new PHPMailer(true);
try {
    // Paramètres SMTP
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'brunoadenhouessou@gmail.com'; // Remplacez par votre adresse e-mail Gmail
    $mail->Password = 'bzefkmnzeoujibcb'; // Remplacez par votre mot de passe Gmail
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;
    // Destinataire et expéditeur
    $mail->setFrom('brunoadenhouessou@gmail.com', 'PortFolio');
    $mail->addAddress($email, "MM/Mme $nom");

    // Contenu de l'e-mail
    $mail->isHTML(true);
    $mail->Subject = 'Commande sur ...';

    // Définissez le contenu de l'e-mail
    $mail->Body =$message;
        // Envoyer l'e-mail
    $mail->send();
    
    echo 'L\'e-mail a été envoyé avec succès.';
    die();
}
catch (Exception $e) {
    echo 'L\'envoi de l\'e-mail a échoué. Erreur : ' . $mail->ErrorInfo;die();
}
    
?>