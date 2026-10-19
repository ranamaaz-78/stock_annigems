{{-- Plain HTML email for Branch Admin credentials (optional send). --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ setting('company_name', 'Aanigemas') }} credentials</title>
</head>
<body style="font-family: Inter, Arial, sans-serif; color: #141d23; background: #f6faff; padding: 24px;">
    <div style="max-width: 480px; margin: 0 auto; background: #fff; border: 1px solid #d3c4af; border-radius: 8px; padding: 24px;">
        <h1 style="font-size: 18px; margin: 0 0 12px;">Welcome to {{ setting('company_name', 'Aanigemas') }}</h1>
        <p style="font-size: 14px; line-height: 1.5;">Hello {{ $user->name }},</p>
        <p style="font-size: 14px; line-height: 1.5;">A Branch Admin account has been created for you. Use the credentials below to sign in. You will be asked to set a new password on first login.</p>
        <p style="font-size: 14px;"><strong>Email:</strong> {{ $user->email }}</p>
        <p style="font-size: 14px;"><strong>Temporary password:</strong> {{ $password }}</p>
        <p style="font-size: 14px;"><a href="{{ $loginUrl }}" style="color: #785600;">Sign in to {{ setting('company_name', 'Aanigemas') }}</a></p>
    </div>
</body>
</html>
