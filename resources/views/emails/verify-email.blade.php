<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verifica tu cuenta de Prixma</title>
</head>
<body style="margin: 0; padding: 0; background-color: #161622; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #161622; padding: 40px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 520px; background-color: #12121e; border-radius: 16px; border: 1px solid #2a2a3a; overflow: hidden;">

          {{-- Header --}}
          <tr>
            <td style="background-color: #0d0d14; padding: 28px 32px; text-align: center;">
              <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
                <tr>
                  <td style="vertical-align: middle; padding-right: 10px;">
                    <div style="width: 32px; height: 32px; background: #9b5dff; border-radius: 9px; text-align: center;">
                      <svg width="18" height="18" viewBox="0 0 18 18" fill="none" style="margin-top: 7px;">
                        <polygon points="9,1.5 16.5,15.5 1.5,15.5" fill="none" stroke="white" stroke-width="1.8" stroke-linejoin="round"/>
                        <line x1="9" y1="1.5" x2="9" y2="15.5" stroke="white" stroke-width="0.9" opacity="0.5"/>
                      </svg>
                    </div>
                  </td>
                  <td style="vertical-align: middle;">
                    <span style="font-size: 20px; font-weight: 500; color: #ffffff; letter-spacing: -0.5px;">prixma</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Body --}}
          <tr>
            <td style="padding: 36px 40px 32px;">

              <p style="font-size: 13px; color: #8e8ea8; margin: 0 0 20px; line-height: 1.5;">
                Hola,
              </p>

              <h1 style="font-size: 22px; font-weight: 500; color: #ffffff; margin: 0 0 14px; line-height: 1.3;">
                Ya casi estás adentro. Solo falta un paso.
              </h1>

              <p style="font-size: 15px; color: #8e8ea8; line-height: 1.65; margin: 0 0 28px;">
                Para activar tu cuenta y empezar a conectar siendo tú, confirma tu correo tocando el botón de abajo.
              </p>

              {{-- CTA --}}
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 28px;">
                <tr>
                  <td align="center">
                    <a href="{{ $verificationUrl }}"
                       style="display: inline-block; background: #9b5dff; color: #ffffff; font-size: 15px; font-weight: 500; padding: 14px 36px; border-radius: 14px; text-decoration: none; letter-spacing: -0.2px;">
                      Verificar mi cuenta
                    </a>
                  </td>
                </tr>
              </table>

              {{-- Nota de expiración --}}
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 24px;">
                <tr>
                  <td style="background-color: #1c1c2e; border-radius: 12px; padding: 14px 16px;">
                    <p style="font-size: 13px; color: #8e8ea8; margin: 0; line-height: 1.5;">
                      &#8987; Este enlace es válido durante
                      <strong style="color: #ffffff; font-weight: 500;">7 días</strong>.
                      Si no lo solicitaste, puedes ignorar este mensaje.
                    </p>
                  </td>
                </tr>
              </table>

              {{-- Fallback URL --}}
              <p style="font-size: 13px; color: #8e8ea8; line-height: 1.6; margin: 0;">
                Si el botón no funciona, copia y pega este enlace en tu navegador:
              </p>
              <p style="font-size: 12px; color: #9b5dff; word-break: break-all; margin: 6px 0 0; line-height: 1.5;">
                {{ $verificationUrl }}
              </p>

            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td style="border-top: 1px solid #2a2a3a; padding: 20px 40px; text-align: center;">
              <p style="font-size: 12px; color: #555568; margin: 0; line-height: 1.6;">
                Conecta siendo tú &mdash;
                <a href="#" style="color: #555568; text-decoration: none;">Términos de uso</a>
                &nbsp;·&nbsp;
                <a href="#" style="color: #555568; text-decoration: none;">Privacidad</a>
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
