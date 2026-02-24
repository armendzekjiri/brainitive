<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify your email — {{ $appName }}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f8fafc; -webkit-font-smoothing: antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f8fafc;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 520px;">
                    <!-- Logo / Brand -->
                    <tr>
                        <td align="center" style="padding-bottom: 32px;">
                            <img src="{{ asset('images/logo.png') }}" alt="{{ $appName }}" width="140" style="max-width: 140px; height: auto; display: block;" />
                        </td>
                    </tr>
                    <!-- Card -->
                    <tr>
                        <td style="background-color: #ffffff; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1); overflow: hidden;">
                            <!-- Accent bar -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="height: 4px; background: linear-gradient(90deg, #7c3aed 0%, #a78bfa 100%);"></td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding: 40px 32px;">
                                <tr>
                                    <td>
                                        <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 700; color: #1e293b; line-height: 1.3;">
                                            Verify your email address
                                        </h1>
                                        <p style="margin: 0 0 24px 0; font-size: 15px; color: #64748b; line-height: 1.6;">
                                            Hi {{ $user->name }},
                                        </p>
                                        <p style="margin: 0 0 28px 0; font-size: 15px; color: #475569; line-height: 1.6;">
                                            Thanks for signing up! Please click the button below to verify your email address and get started.
                                        </p>
                                        <!-- Button -->
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 28px 0;">
                                            <tr>
                                                <td style="border-radius: 10px; background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); box-shadow: 0 2px 4px rgba(124, 58, 237, 0.3);">
                                                    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" style="display: inline-block; padding: 14px 28px; font-size: 15px; font-weight: 600; color: #ffffff; text-decoration: none;">
                                                        Verify email address
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin: 0; font-size: 13px; color: #94a3b8; line-height: 1.6;">
                                            If you didn’t create an account, you can safely ignore this email.
                                        </p>
                                        <p style="margin: 16px 0 0 0; font-size: 12px; color: #cbd5e1; line-height: 1.5;">
                                            Button not working? Copy and paste this link into your browser:<br>
                                            <a href="{{ $url }}" style="color: #7c3aed; word-break: break-all;">{{ $url }}</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding-top: 24px;">
                            <p style="margin: 0; font-size: 12px; color: #94a3b8;">
                                © {{ date('Y') }} {{ $appName }}. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
