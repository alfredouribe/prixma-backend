<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Verifica tu cuenta de Prixma</title>
  <style>
    @font-face {
      font-family: 'Poppins';
      src: url('{{ asset('prixma_resources/fonts/Poppins-Regular.ttf') }}') format('truetype');
      font-weight: 400;
    }
    @font-face {
      font-family: 'Poppins';
      src: url('{{ asset('prixma_resources/fonts/Poppins-Medium.ttf') }}') format('truetype');
      font-weight: 500;
    }
    @font-face {
      font-family: 'Poppins';
      src: url('{{ asset('prixma_resources/fonts/Poppins-SemiBold.ttf') }}') format('truetype');
      font-weight: 600;
    }
    @font-face {
      font-family: 'Poppins';
      src: url('{{ asset('prixma_resources/fonts/Poppins-Bold.ttf') }}') format('truetype');
      font-weight: 700;
    }
  </style>
</head>
<body style="margin: 0; padding: 0; background-color: #161622; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">

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
                    <img src="{{ asset('prixma_resources/png/appiconColor.png') }}"
                         width="32" height="32" alt="Prixma"
                         style="display: block; border-radius: 9px;">
                  </td>
                  <td style="vertical-align: middle;">
                    <span style="font-size: 20px; font-weight: 500; color: #ffffff; letter-spacing: -0.5px; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">prixma</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- Body --}}
          <tr>
            <td style="padding: 36px 40px 32px;">

              <p style="font-size: 13px; color: #8e8ea8; margin: 0 0 20px; line-height: 1.5; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
                Hola,
              </p>

              <h1 style="font-size: 22px; font-weight: 500; color: #ffffff; margin: 0 0 14px; line-height: 1.3; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
                Ya casi estás adentro. Solo falta un paso.
              </h1>

              <p style="font-size: 15px; color: #8e8ea8; line-height: 1.65; margin: 0 0 28px; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
                Para activar tu cuenta y empezar a conectar siendo tú, confirma tu correo tocando el botón de abajo.
              </p>

              {{-- CTA --}}
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 28px;">
                <tr>
                  <td align="center">
                    <a href="{{ $verificationUrl }}"
                       style="display: inline-block; background: #9b5dff; color: #ffffff; font-size: 15px; font-weight: 500; padding: 14px 36px; border-radius: 14px; text-decoration: none; letter-spacing: -0.2px; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
                      Verificar mi cuenta
                    </a>
                  </td>
                </tr>
              </table>

              {{-- Nota de expiración --}}
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 0 0 24px;">
                <tr>
                  <td style="background-color: #161622; border-radius: 12px; padding: 14px 16px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
                      <tr>
                        <td style="vertical-align: top; padding-right: 8px; padding-top: 1px; width: 18px;">
                          <svg width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="7" cy="7" r="5.5" stroke="#8e8ea8" stroke-width="1.2"/>
                            <path d="M7 4.5V7L8.5 8.5" stroke="#8e8ea8" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                          </svg>
                        </td>
                        <td style="vertical-align: top;">
                          <p style="font-size: 13px; color: #8e8ea8; margin: 0; line-height: 1.5; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
                            Este enlace es válido durante
                            <strong style="color: #ffffff; font-weight: 500;">7 días</strong>.
                            Si no lo solicitaste, puedes ignorar este mensaje.
                          </p>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
              </table>

              {{-- Fallback URL --}}
              <p style="font-size: 13px; color: #8e8ea8; line-height: 1.6; margin: 0; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
                Si el botón no funciona, copia y pega este enlace en tu navegador:
              </p>
              <p style="font-size: 12px; color: #9b5dff; word-break: break-all; margin: 6px 0 0; line-height: 1.5; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
                {!! $verificationUrl !!}
              </p>

            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td style="border-top: 1px solid #2a2a3a; padding: 20px 40px; text-align: center;">
              <p style="font-size: 12px; color: #555568; margin: 0; line-height: 1.6; font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
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
