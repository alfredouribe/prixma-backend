<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Prixma — API</title>
        <meta name="description" content="Backend de Prixma. Toda la experiencia de la app vive en el cliente móvil.">

        <style>
            @font-face {
                font-family: 'Poppins';
                src: url('/prixma_resources/fonts/Poppins-Regular.ttf') format('truetype');
                font-weight: 400;
                font-display: swap;
            }
            @font-face {
                font-family: 'Poppins';
                src: url('/prixma_resources/fonts/Poppins-Medium.ttf') format('truetype');
                font-weight: 500;
                font-display: swap;
            }
            @font-face {
                font-family: 'Poppins';
                src: url('/prixma_resources/fonts/Poppins-SemiBold.ttf') format('truetype');
                font-weight: 600;
                font-display: swap;
            }

            :root {
                --dark: #0d0d14;
                --purple: #9b5dff;
                --green: #33d17a;
                --text-secondary: rgba(255, 255, 255, 0.6);
                --text-tertiary: rgba(255, 255, 255, 0.4);
            }

            * {
                box-sizing: border-box;
            }

            html, body {
                margin: 0;
                padding: 0;
                height: 100%;
            }

            body {
                background: var(--dark);
                color: #ffffff;
                font-family: 'Poppins', ui-sans-serif, system-ui, sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 24px;
                position: relative;
                overflow: hidden;
            }

            .glow {
                position: absolute;
                width: 640px;
                height: 640px;
                border-radius: 50%;
                background: radial-gradient(circle, rgba(155, 93, 255, 0.35) 0%, rgba(155, 93, 255, 0) 70%);
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                pointer-events: none;
            }

            main {
                position: relative;
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
                max-width: 420px;
            }

            .logo {
                width: 220px;
                max-width: 70vw;
                height: auto;
                margin-bottom: 28px;
            }

            .status-pill {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 6px 14px;
                border-radius: 999px;
                background: rgba(51, 209, 122, 0.12);
                border: 1px solid rgba(51, 209, 122, 0.3);
                font-size: 13px;
                font-weight: 500;
                color: var(--green);
                margin-bottom: 20px;
            }

            .status-dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                background: var(--green);
                box-shadow: 0 0 0 0 rgba(51, 209, 122, 0.6);
                animation: pulse 2s infinite;
            }

            @keyframes pulse {
                0% { box-shadow: 0 0 0 0 rgba(51, 209, 122, 0.5); }
                70% { box-shadow: 0 0 0 8px rgba(51, 209, 122, 0); }
                100% { box-shadow: 0 0 0 0 rgba(51, 209, 122, 0); }
            }

            p.description {
                font-size: 15px;
                line-height: 1.6;
                color: var(--text-secondary);
                margin: 0 0 32px;
            }

            footer {
                position: absolute;
                bottom: 28px;
                font-size: 12px;
                color: var(--text-tertiary);
            }
        </style>
    </head>
    <body>
        <div class="glow"></div>

        <main>
            <img class="logo" src="/prixma_resources/png/logo_full_white.png" alt="Prixma">

            <span class="status-pill">
                <span class="status-dot"></span>
                API activa
            </span>

            <p class="description">
                Este es el backend de Prixma — aquí no hay nada que ver.<br>
                La app vive en tu teléfono. Conecta siendo tú.
            </p>
        </main>

        <footer>&copy; {{ date('Y') }} Prixma</footer>
    </body>
</html>
