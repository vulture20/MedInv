{{--
    Invitation mail template (UserInvitationMail). Plain, dependency-free
    HTML rather than Laravel's markdown mail components — this project has
    no other mail templates yet to stay consistent with, and keeping it
    self-contained avoids pulling in the vendor markdown mail CSS/layout.
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>MedInv — Zugang eingerichtet</title>
</head>
<body style="font-family: sans-serif; color: #1a1a1a;">
    <p>Hallo {{ $name }},</p>

    <p>
        für Sie wurde ein Zugang zu MedInv eingerichtet.
    </p>

    <table cellpadding="4" cellspacing="0">
        <tr>
            <td><strong>Adresse:</strong></td>
            <td><a href="{{ $url }}">{{ $url }}</a></td>
        </tr>
        <tr>
            <td><strong>Benutzername:</strong></td>
            <td>{{ $name }}</td>
        </tr>
        <tr>
            <td><strong>E-Mail-Adresse:</strong></td>
            <td>{{ $email }}</td>
        </tr>
    </table>

    <p>
        Ihre Zugangsdaten haben Sie separat von Ihrem Administrator erhalten.
    </p>

    <p>Diese E-Mail wurde automatisch von MedInv versendet.</p>
</body>
</html>
