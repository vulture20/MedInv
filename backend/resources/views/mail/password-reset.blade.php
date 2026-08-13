{{--
    Password reset mail template (PasswordResetMail). Same dependency-free
    HTML approach as user-invitation.blade.php — no Laravel markdown mail
    components — plus the actual MedInv logo (resources/mail/logo.svg,
    inlined as a data URI so it survives even with remote images blocked)
    and bilingual content (German first, English second): an anonymous
    password-reset request has no reliable locale to pick from, see
    PasswordResetMail's docblock.
--}}
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>MedInv — Passwort zurücksetzen / Reset your password</title>
</head>
<body style="font-family: sans-serif; color: #1a1a1a;">
    <p>
        <img src="{{ $logoDataUri }}" alt="MedInv" width="32" height="32" style="vertical-align: middle; border-radius: 6px;">
        <strong style="font-size: 1.15em; vertical-align: middle;"> MedInv</strong>
    </p>

    <hr style="border: none; border-top: 1px solid #e0e0e3;">

    <h2 style="margin-bottom: 0;">Passwort zurücksetzen</h2>
    <p>Hallo {{ $name }},</p>
    <p>
        für Ihr MedInv-Konto wurde eine Anfrage zum Zurücksetzen des Passworts gestellt.
        Klicken Sie auf den folgenden Link, um ein neues Passwort zu vergeben:
    </p>
    <p><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
    <p>
        Dieser Link ist {{ $expireMinutes }} Minuten gültig.
        Wenn Sie diese Anfrage nicht gestellt haben, können Sie diese E-Mail ignorieren
        — Ihr Passwort bleibt unverändert.
    </p>

    <hr style="border: none; border-top: 1px solid #e0e0e3;">

    <h2 style="margin-bottom: 0;">Reset your password</h2>
    <p>Hello {{ $name }},</p>
    <p>
        A request was received to reset the password for your MedInv account.
        Click the link below to choose a new password:
    </p>
    <p><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
    <p>
        This link is valid for {{ $expireMinutes }} minutes.
        If you did not request this, you can safely ignore this email
        — your password will remain unchanged.
    </p>

    <hr style="border: none; border-top: 1px solid #e0e0e3;">

    <p style="font-size: 0.85em; color: #6b6b70;">
        &copy; {{ date('Y') }} MedInv
    </p>
</body>
</html>
