<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You're invited to {{ $workspace->name }} — {{ $appName }}</title>
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
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #F4F6F8; -webkit-font-smoothing: antialiased;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #F4F6F8;">
        <tr>
            <td align="center" style="padding: 48px 24px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 520px;">
                    <!-- Logo / Brand -->
                    <tr>
                        <td align="center" style="padding-bottom: 32px;">
                            <img src="{{ asset('images/logo.png') }}" alt="{{ $appName }}" width="140" style="max-width: 140px; height: auto; display: block;" />
                        </td>
                    </tr>
                    <!-- Card -->
                    <tr>
                        <td style="background-color: #ffffff; border-radius: 20px; box-shadow: 0 4px 24px rgba(72, 94, 114, 0.08); overflow: hidden; border: 1px solid rgba(192, 197, 192, 0.4);">
                            <!-- Accent bar (brand blue → green) -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td style="height: 5px; background: linear-gradient(90deg, #0E83DC 0%, #69A748 100%);"></td>
                                </tr>
                            </table>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding: 40px 36px;">
                                <tr>
                                    <td>
                                        <!-- Icon + Title -->
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-bottom: 20px;">
                                            <tr>
                                                <td style="width: 48px; height: 48px; background: linear-gradient(135deg, #0E83DC 0%, #0b6fbf 100%); border-radius: 12px; text-align: center; vertical-align: middle;">
                                                    <span style="font-size: 24px; line-height: 48px;">👋</span>
                                                </td>
                                                <td style="padding-left: 16px; vertical-align: middle;">
                                                    <h1 style="margin: 0; font-size: 22px; font-weight: 700; color: #485E72; line-height: 1.3;">
                                                        You're invited to join a workspace
                                                    </h1>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin: 0 0 20px 0; font-size: 15px; color: #485E72; line-height: 1.6;">
                                            <strong>{{ $inviter->name }}</strong> has invited you to join <strong>{{ $workspace->name }}</strong> on {{ $appName }}.
                                        </p>
                                        <p style="margin: 0 0 24px 0; font-size: 15px; color: #485E72; line-height: 1.6;">
                                            You'll join as <span style="display: inline-block; padding: 4px 10px; background: rgba(14, 131, 220, 0.1); color: #0E83DC; border-radius: 6px; font-weight: 600;">{{ $roleLabel }}</span>. Accept the invitation to get started—create an account or sign in if you already have one.
                                        </p>
                                        <!-- CTA Button -->
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin: 0 0 28px 0;">
                                            <tr>
                                                <td style="border-radius: 12px; background: linear-gradient(135deg, #0E83DC 0%, #0b6fbf 100%); box-shadow: 0 4px 14px rgba(14, 131, 220, 0.35);">
                                                    <a href="{{ $acceptUrl }}" target="_blank" rel="noopener noreferrer" style="display: inline-block; padding: 16px 32px; font-size: 16px; font-weight: 600; color: #ffffff; text-decoration: none;">
                                                        Accept invitation
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin: 0; font-size: 13px; color: #485E72; opacity: 0.7; line-height: 1.6;">
                                            This invitation is valid for 30 days. If you sign up within that time, you'll join the workspace automatically. If you didn't expect this email, you can safely ignore it.
                                        </p>
                                        <p style="margin: 20px 0 0 0; font-size: 12px; color: #C0C5C0; line-height: 1.5;">
                                            Button not working? Copy and paste this link into your browser:<br>
                                            <a href="{{ $acceptUrl }}" style="color: #0E83DC; word-break: break-all;">{{ $acceptUrl }}</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding-top: 32px;">
                            <p style="margin: 0; font-size: 12px; color: #485E72; opacity: 0.6;">
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
