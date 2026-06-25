# API Reference — Prixma Backend

Base URL: `https://api.prixma.app` (producción) / `http://localhost:8000` (local)

Todos los endpoints devuelven JSON. El header `Accept: application/json` es requerido en todas las peticiones.

---

## Tabla de contenidos

- [General](#general)
- [Auth](#auth)
  - [POST /api/auth/register](#post-apiauthregister)
  - [POST /api/auth/login](#post-apiauthlogin)
  - [POST /api/auth/logout](#post-apiauthlogout)
  - [GET /api/auth/me](#get-apiauthme)
  - [POST /api/auth/forgot-password](#post-apiauthforgot-password)
  - [POST /api/auth/reset-password](#post-apiauthreset-password)
- [Onboarding](#onboarding)
  - [GET /api/onboarding/catalogs](#get-apionboardingcatalogs)
  - [GET /api/onboarding/status](#get-apionboardingstatus)
  - [POST /api/onboarding/step/identity](#post-apionboardingstepidentity)
  - [POST /api/onboarding/step/pronouns](#post-apionboardingsteppronouns)
  - [POST /api/onboarding/step/intention](#post-apionboardingstepintention)
  - [POST /api/onboarding/step/interests](#post-apionboardingstepinterests)
  - [POST /api/onboarding/step/video](#post-apionboardingstepvideo)
  - [POST /api/onboarding/step/safety](#post-apionboardingstepsafety)
  - [POST /api/onboarding/video/presigned-url](#post-apionboardingvideopresigned-url)
  - [POST /api/onboarding/video/upload](#post-apionboardingvideoupload)
- [Profile](#profile)
  - [GET /api/profiles/me](#get-apiprofilesme)
  - [PUT /api/profiles/me](#put-apiprofilesme)
  - [GET /api/profiles/:uuid](#get-apiprofilesuuid)
  - [POST /api/profiles/me/photos](#post-apiprofilesmephotos)
  - [DELETE /api/profiles/me/photos/:uuid](#delete-apiprofilesmephotosuuid)
  - [PATCH /api/profiles/me/photos/reorder](#patch-apiprofilesmephotosreorder)
  - [POST /api/profiles/me/video/presigned-url](#post-apiprofilesmevideo-presigned-url)
  - [POST /api/profiles/me/video](#post-apiprofilesmevideo)
  - [DELETE /api/profiles/me/video](#delete-apiprofilesmevideo)
- [Matching](#matching)
  - [GET /api/matching/explore](#get-apimatchingexplore)
  - [POST /api/matching/swipe](#post-apimatchingswipe)
  - [GET /api/matching/matches](#get-apimatchingmatches)
  - [GET /api/matching/preferences](#get-apimatchingpreferences)
  - [PUT /api/matching/preferences](#put-apimatchingpreferences)

---

## General

### GET /api/health

**Descripción:** Verifica que el servidor está activo.
**Auth requerida:** No

**Response exitoso (200):**
```json
{ "status": "ok" }
```

---

## Auth

> Todos los endpoints bajo `/api/auth/register`, `/api/auth/login`, `/api/auth/forgot-password` y `/api/auth/reset-password` están protegidos con rate limiting: máximo 5 intentos por minuto por IP (`throttle:auth`).

---

### POST /api/auth/register

**Descripción:** Crea una nueva cuenta de usuario y devuelve un token de acceso Sanctum.
**Auth requerida:** No

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `email` | string | Sí | Correo electrónico único del usuario |
| `password` | string | Sí | Contraseña (mínimo 8 caracteres) |
| `password_confirmation` | string | Sí | Debe coincidir con `password` |
| `date_of_birth` | string (date) | Sí | Fecha de nacimiento; el usuario debe tener al menos 18 años |
| `terms_accepted` | boolean | Sí | Debe ser `true`; acepta los Términos de Uso |
| `privacy_accepted` | boolean | Sí | Debe ser `true`; acepta la Política de Privacidad |

**Comportamiento adicional:** Al registrarse se envía un correo de verificación de email en cola (no bloquea el registro).

**Response exitoso (201):**
```json
{
  "data": {
    "id": "uuid",
    "email": "usuario@ejemplo.com",
    "status": "active",
    "onboarding_completed": false,
    "email_verified_at": null,
    "created_at": "2025-01-01T00:00:00Z"
  },
  "token": "1|plainTextToken...",
  "message": "Cuenta creada exitosamente."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 422 | Validación fallida (email duplicado, contraseñas no coinciden, menor de 18 años, términos no aceptados) |
| 429 | Rate limit superado |

---

### POST /api/auth/login

**Descripción:** Autentica al usuario con email y contraseña, devuelve un token de acceso Sanctum.
**Auth requerida:** No

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `email` | string | Sí | Correo registrado |
| `password` | string | Sí | Contraseña del usuario |

**Comportamiento adicional:** Si la cuenta tiene status `banned` o `suspended`, el login es rechazado con 403.

**Response exitoso (200):**
```json
{
  "data": {
    "id": "uuid",
    "email": "usuario@ejemplo.com",
    "status": "active",
    "onboarding_completed": false,
    "email_verified_at": "2025-01-01T00:00:00Z",
    "created_at": "2025-01-01T00:00:00Z"
  },
  "token": "2|plainTextToken..."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Credenciales incorrectas |
| 403 | Cuenta baneada o suspendida |
| 422 | Validación fallida (campos faltantes o formato inválido) |
| 429 | Rate limit superado |

---

### POST /api/auth/logout

**Descripción:** Revoca el token de acceso actual del usuario autenticado.
**Auth requerida:** Sí (`Authorization: Bearer {token}`)

**Parámetros de request:** Ninguno

**Response exitoso (204):** Sin cuerpo.

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token o token inválido |

---

### GET /api/auth/me

**Descripción:** Devuelve los datos del usuario actualmente autenticado.
**Auth requerida:** Sí

**Parámetros de request:** Ninguno

**Response exitoso (200):**
```json
{
  "data": {
    "id": "uuid",
    "email": "usuario@ejemplo.com",
    "status": "active",
    "onboarding_completed": true,
    "email_verified_at": "2025-01-01T00:00:00Z",
    "created_at": "2025-01-01T00:00:00Z"
  }
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token o token inválido |

---

### POST /api/auth/forgot-password

**Descripción:** Envía un correo con enlace para restablecer contraseña. Por seguridad, siempre responde el mismo mensaje independientemente de si el email existe o no.
**Auth requerida:** No

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `email` | string | Sí | Correo asociado a la cuenta |

**Response exitoso (200):**
```json
{
  "message": "Si existe una cuenta con ese correo, recibirás instrucciones."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 422 | Formato de email inválido |
| 429 | Rate limit superado |

---

### POST /api/auth/reset-password

**Descripción:** Restablece la contraseña usando el token recibido por correo.
**Auth requerida:** No

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `token` | string | Sí | Token de recuperación del enlace enviado por correo |
| `email` | string | Sí | Correo asociado a la cuenta |
| `password` | string | Sí | Nueva contraseña (mínimo 8 caracteres) |
| `password_confirmation` | string | Sí | Debe coincidir con `password` |

**Response exitoso (200):**
```json
{
  "message": "Contraseña actualizada correctamente."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 422 | Token expirado o inválido, usuario no encontrado, contraseñas no coinciden |
| 429 | Rate limit superado |

---

## Onboarding

> Todos los endpoints de onboarding requieren autenticación (`Authorization: Bearer {token}`).

El onboarding se divide en 6 pasos secuenciales. El campo `onboarding_step` en el perfil indica el último paso completado (0–6). El paso 6 marca el onboarding como completado.

---

### GET /api/onboarding/catalogs

**Descripción:** Devuelve todos los catálogos necesarios para mostrar las opciones del onboarding: identidades de género, orientaciones sexuales, pronombres e intereses agrupados por categoría.
**Auth requerida:** Sí

**Parámetros de request:** Ninguno

**Response exitoso (200):**
```json
{
  "data": {
    "gender_identities": [
      { "id": "uuid", "slug": "mujer-trans", "label": "Mujer trans" }
    ],
    "orientations": [
      { "id": "uuid", "slug": "bisexual", "label": "Bisexual" }
    ],
    "pronouns": [
      { "id": "uuid", "slug": "elle", "label": "Elle/Elle" }
    ],
    "interests": {
      "cultura": [
        { "id": "uuid", "slug": "cine", "label": "Cine", "category": "cultura" }
      ],
      "deporte": [
        { "id": "uuid", "slug": "yoga", "label": "Yoga", "category": "deporte" }
      ]
    }
  }
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token o token inválido |

---

### GET /api/onboarding/status

**Descripción:** Devuelve el estado actual del onboarding del usuario: paso actual, si está completado y el perfil con sus relaciones cargadas.
**Auth requerida:** Sí

**Parámetros de request:** Ninguno

**Response exitoso (200):**
```json
{
  "data": {
    "current_step": 3,
    "completed": false,
    "profile": {
      "id": "uuid",
      "display_name": "Alex",
      "bio": null,
      "city": null,
      "intention": "friendship",
      "onboarding_step": 3,
      "onboarding_completed": false,
      "gender_identities": [...],
      "orientations": [...],
      "pronouns": [...],
      "interests": [...]
    }
  }
}
```

**Notas:** `profile` es `null` si el usuario nunca inició el onboarding (paso 0).

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token o token inválido |

---

### POST /api/onboarding/step/identity

**Descripción:** Guarda el primer paso del onboarding: nombre de display, identidades de género y orientaciones sexuales. Crea el perfil si no existe.
**Auth requerida:** Sí

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `display_name` | string | Sí | Nombre visible (máx. 50 caracteres) |
| `gender_identity_ids` | array de UUID | No* | IDs de identidades de género del catálogo |
| `custom_gender_identity` | string | No* | Descripción libre de identidad de género (máx. 100 caracteres) |
| `orientation_ids` | array de UUID | No* | IDs de orientaciones del catálogo |
| `custom_orientation` | string | No* | Descripción libre de orientación (máx. 100 caracteres) |

*Se requiere al menos uno de `gender_identity_ids` o `custom_gender_identity`. Lo mismo aplica para orientación.

**Response exitoso (200):**
```json
{
  "data": {
    "id": "uuid",
    "display_name": "Alex",
    "onboarding_step": 1,
    "gender_identities": [
      { "id": "uuid", "slug": "no-binarie", "label": "No binarie" }
    ],
    "orientations": [
      { "id": "uuid", "slug": "pansexual", "label": "Pansexual" }
    ]
  },
  "message": "Identidad guardada."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 422 | Validación fallida (falta display_name, ni identidad ni custom definidos) |

---

### POST /api/onboarding/step/pronouns

**Descripción:** Guarda el segundo paso: pronombres del usuario y opcionalmente la URL de foto de perfil.
**Auth requerida:** Sí

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `pronoun_ids` | array de UUID | No* | IDs de pronombres del catálogo |
| `custom_pronouns` | string | No* | Pronombres en palabras propias (máx. 100 caracteres) |
| `photo_url` | string (URL) | No | URL de foto de perfil (máx. 500 caracteres) |

*Se requiere al menos uno de `pronoun_ids` o `custom_pronouns`.

**Nota:** Para subir la foto primero se utiliza un flujo S3 externo; la URL resultante se envía en este campo.

**Response exitoso (200):**
```json
{
  "data": {
    "id": "uuid",
    "display_name": "Alex",
    "onboarding_step": 2,
    "pronouns": [
      { "id": "uuid", "slug": "elle", "label": "Elle/Elle" }
    ]
  },
  "message": "Pronombres guardados."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 422 | Validación fallida (ni pronombres del catálogo ni custom definidos) |
| 422 | Perfil no existe (paso de identidad no completado) |

---

### POST /api/onboarding/step/intention

**Descripción:** Guarda el tercer paso: la intención principal de uso de la app.
**Auth requerida:** Sí

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `intention` | string (enum) | Sí | Valores posibles: `partner`, `friendship`, `community`, `mentorship` |

**Response exitoso (200):**
```json
{
  "data": {
    "id": "uuid",
    "display_name": "Alex",
    "intention": "friendship",
    "onboarding_step": 3
  },
  "message": "Intención guardada."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 422 | Valor de `intention` no válido o perfil inexistente |

---

### POST /api/onboarding/step/interests

**Descripción:** Guarda el cuarto paso: intereses del usuario. Requiere un mínimo de 3 intereses combinando catálogo y campo libre.
**Auth requerida:** Sí

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `interest_ids` | array de UUID | No* | IDs de intereses del catálogo |
| `custom_interests` | string | No* | Intereses en palabras propias (máx. 200 caracteres; cuenta como 1 interés adicional) |

*El total de `interest_ids` + (1 si `custom_interests` está relleno) debe ser >= 3.

**Response exitoso (200):**
```json
{
  "data": {
    "id": "uuid",
    "display_name": "Alex",
    "onboarding_step": 4,
    "interests": [
      { "id": "uuid", "slug": "cine", "label": "Cine", "category": "cultura" }
    ]
  },
  "message": "Intereses guardados."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 422 | Menos de 3 intereses en total o perfil inexistente |

---

### POST /api/onboarding/step/video

**Descripción:** Registra el quinto paso: notifica al backend que el video ya fue subido a S3 directamente. El video es puesto en cola para procesamiento asíncrono.
**Auth requerida:** Sí

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `video_key` | string | Sí | Clave S3 del video subido (obtenida previamente de `/api/onboarding/video/presigned-url`) |

**Flujo recomendado:**
1. Llamar a `POST /api/onboarding/video/presigned-url` para obtener la URL pre-firmada y el `video_key`.
2. Subir el video directamente a S3 con la URL pre-firmada (PUT).
3. Llamar a este endpoint con el `video_key` para registrar el video en el backend.

**Response exitoso (200):**
```json
{
  "data": {
    "id": "uuid",
    "display_name": "Alex",
    "onboarding_step": 5,
    "video_processed": false
  },
  "message": "Video recibido. Será procesado en breve."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 422 | `video_key` faltante o perfil inexistente |

---

### POST /api/onboarding/step/safety

**Descripción:** Guarda el sexto y último paso: preferencias de seguridad del usuario. Marca el onboarding como completado.
**Auth requerida:** Sí

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `selfie_verification_enabled` | boolean | Sí | Activar verificación por selfie |
| `incognito_mode_enabled` | boolean | Sí | Activar modo incógnito |
| `geo_block_enabled` | boolean | Sí | Activar bloqueo geográfico |
| `reports_enabled` | boolean | Sí | Activar sistema de reportes |

**Response exitoso (200):**
```json
{
  "message": "¡Onboarding completado! Bienvenide a Prixma."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 422 | Campos faltantes o perfil inexistente |

---

### POST /api/onboarding/video/presigned-url

**Descripción:** Genera una URL pre-firmada de S3 para que el cliente suba el video de presentación directamente, sin pasar por el servidor Laravel. La URL expira en 15 minutos.
**Auth requerida:** Sí

**Parámetros de request:** Ninguno

**Response exitoso (200):**
```json
{
  "data": {
    "upload_url": "https://bucket.s3.amazonaws.com/videos/profiles/uuid/uuid.mp4?X-Amz-Signature=...",
    "video_key": "videos/profiles/uuid/uuid.mp4"
  }
}
```

**Uso:** El cliente hace un `PUT` a `upload_url` con el archivo de video (`Content-Type: video/mp4`). Luego envía `video_key` al endpoint `POST /api/onboarding/step/video`.

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |

---

### POST /api/onboarding/video/upload

**Descripción:** Alternativa al flujo pre-firmado: sube el video directamente al servidor Laravel (multipart/form-data), que lo guarda en S3 y encola el procesamiento. Máximo 200 MB.
**Auth requerida:** Sí

**Parámetros de request (multipart/form-data):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `video` | file | Sí | Archivo de video (máx. 200 MB) |

**Response exitoso (202):**
```json
{
  "message": "Video recibido. Está siendo procesado."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 422 | Archivo faltante, no es un archivo válido, o supera 200 MB |

---

## Profile

> Todos los endpoints de perfil requieren autenticación (`Authorization: Bearer {token}`).

---

### GET /api/profiles/me

**Descripción:** Devuelve el perfil completo del usuario autenticado, incluyendo todas sus relaciones (identidades, orientaciones, pronombres, intereses, fotos) y estadísticas de actividad.
**Auth requerida:** Sí

**Parámetros de request:** Ninguno

**Response exitoso (200):**
```json
{
  "data": {
    "id": "uuid",
    "display_name": "Alex",
    "bio": "Amante del cine y los libros.",
    "city": "Ciudad de México",
    "intention": "friendship",
    "custom_gender_identity": null,
    "custom_orientation": null,
    "custom_pronouns": null,
    "custom_interests": null,
    "photo_url": "https://bucket.s3.amazonaws.com/photos/profiles/uuid/foto.jpg",
    "video_url": "https://bucket.s3.amazonaws.com/...?X-Amz-Signature=...",
    "video_thumbnail_url": "https://bucket.s3.amazonaws.com/...?X-Amz-Signature=...",
    "video_processed": true,
    "onboarding_step": 6,
    "onboarding_completed": true,
    "gender_identities": [{ "id": "uuid", "slug": "no-binarie", "label": "No binarie" }],
    "orientations": [{ "id": "uuid", "slug": "pansexual", "label": "Pansexual" }],
    "pronouns": [{ "id": "uuid", "slug": "elle", "label": "Elle/Elle" }],
    "interests": [{ "id": "uuid", "slug": "cine", "label": "Cine", "category": "cultura" }],
    "photos": [
      { "id": "uuid", "url": "https://...", "position": 1 }
    ],
    "statistics": {
      "likes_received": 42,
      "matches_count": 7,
      "events_count": 3
    }
  }
}
```

**Notas:**
- `video_url` y `video_thumbnail_url` son URLs pre-firmadas de S3 con expiración de 4 horas. Solo se incluyen si `video_processed` es `true`.
- `statistics` incluye: likes recibidos, número de matches y eventos asistidos.

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 404 | El usuario no tiene perfil creado aún |

---

### PUT /api/profiles/me

**Descripción:** Actualiza uno o más campos del perfil del usuario autenticado. Todos los campos son opcionales (PATCH semántico con método PUT).
**Auth requerida:** Sí

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `display_name` | string | No | Nombre visible (máx. 50 caracteres) |
| `bio` | string\|null | No | Biografía corta (máx. 300 caracteres) |
| `city` | string\|null | No | Ciudad del usuario (máx. 100 caracteres) |
| `intention` | string | No | `partner`, `friendship`, `community` o `mentorship` |
| `gender_identity_ids` | array de UUID | No | Reemplaza las identidades de género actuales |
| `custom_gender_identity` | string\|null | No | Descripción libre (máx. 100 caracteres) |
| `orientation_ids` | array de UUID | No | Reemplaza las orientaciones actuales |
| `custom_orientation` | string\|null | No | Descripción libre (máx. 100 caracteres) |
| `pronoun_ids` | array de UUID | No | Reemplaza los pronombres actuales |
| `custom_pronouns` | string\|null | No | Descripción libre (máx. 100 caracteres) |
| `interest_ids` | array de UUID | No | Reemplaza los intereses actuales |
| `custom_interests` | string\|null | No | Intereses en palabras propias (máx. 200 caracteres) |

**Response exitoso (200):**
```json
{
  "data": {
    "id": "uuid",
    "display_name": "Alex",
    "bio": "Nueva bio.",
    "city": "Guadalajara",
    "intention": "partner",
    "gender_identities": [...],
    "orientations": [...],
    "pronouns": [...],
    "interests": [...],
    "photos": [...]
  },
  "message": "Perfil actualizado."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 404 | El usuario no tiene perfil |
| 422 | Valores fuera de rango o formato inválido |

---

### GET /api/profiles/:uuid

**Descripción:** Devuelve el perfil público de otro usuario por su UUID. No incluye coordenadas exactas, estadísticas privadas ni datos sensibles.
**Auth requerida:** Sí

**Parámetros de ruta:**
| Parámetro | Tipo | Descripción |
|---|---|---|
| `uuid` | UUID | ID del perfil a consultar |

**Response exitoso (200):**
```json
{
  "data": {
    "id": "uuid",
    "display_name": "Jordan",
    "bio": "Fan del jazz y la montaña.",
    "city": "Monterrey",
    "intention": "community",
    "photo_url": "https://...",
    "video_url": "https://...?X-Amz-Signature=...",
    "gender_identities": [...],
    "orientations": [...],
    "pronouns": [...],
    "interests": [...],
    "photos": [
      { "id": "uuid", "url": "https://...", "position": 1 }
    ]
  }
}
```

**Notas:**
- `video_url` solo se incluye si el video está procesado.
- Las coordenadas geográficas nunca se exponen en este endpoint.

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 404 | Perfil no encontrado |

---

### POST /api/profiles/me/photos

**Descripción:** Sube una foto al perfil del usuario autenticado. Máximo 6 fotos por perfil. La primera foto subida se establece automáticamente como `photo_url` del perfil.
**Auth requerida:** Sí

**Parámetros de request (multipart/form-data):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `photo` | file (imagen) | Sí | Formatos: jpeg, jpg, png, webp. Máx. 10 MB |

**Response exitoso (201):**
```json
{
  "data": {
    "id": "uuid",
    "url": "https://bucket.s3.amazonaws.com/photos/profiles/uuid/uuid.jpg",
    "position": 2
  },
  "message": "Foto agregada."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 404 | El usuario no tiene perfil |
| 422 | Archivo no es imagen, formato no soportado, supera 10 MB |
| 422 | El perfil ya tiene 6 fotos (máximo permitido) |

---

### DELETE /api/profiles/me/photos/:uuid

**Descripción:** Elimina una foto del perfil del usuario autenticado por su UUID. Reordena las posiciones de las fotos restantes y actualiza `photo_url` del perfil a la primera foto restante.
**Auth requerida:** Sí

**Parámetros de ruta:**
| Parámetro | Tipo | Descripción |
|---|---|---|
| `uuid` | UUID | ID de la foto a eliminar |

**Response exitoso (200):**
```json
{
  "message": "Foto eliminada."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 404 | Foto no encontrada o no pertenece al perfil del usuario |

---

### PATCH /api/profiles/me/photos/reorder

**Descripción:** Reordena las fotos del perfil del usuario autenticado. La primera foto del array resultante se convierte en el `photo_url` del perfil.
**Auth requerida:** Sí

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `ordered_ids` | array de UUID | Sí | IDs de las fotos en el nuevo orden deseado (mínimo 1 elemento) |

**Response exitoso (200):**
```json
{
  "message": "Orden actualizado."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 404 | El usuario no tiene perfil |
| 422 | Array vacío, UUIDs inválidos, o una foto no pertenece al perfil |

---

### POST /api/profiles/me/video/presigned-url

**Descripción:** Genera una URL pre-firmada de S3 para que el cliente suba el video de presentación directamente al almacenamiento, sin pasar por el servidor. La URL expira en 15 minutos.
**Auth requerida:** Sí

**Parámetros de request:** Ninguno

**Response exitoso (200):**
```json
{
  "data": {
    "upload_url": "https://bucket.s3.amazonaws.com/videos/profiles/uuid/uuid.mp4?X-Amz-Signature=...",
    "video_key": "videos/profiles/uuid/uuid.mp4"
  }
}
```

**Uso:** El cliente hace un `PUT` a `upload_url` con `Content-Type: video/mp4`. Luego notifica al backend con `POST /api/profiles/me/video` enviando el archivo o registrando la key.

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |

---

### POST /api/profiles/me/video

**Descripción:** Sube el video de presentación directamente al servidor Laravel (multipart/form-data). El servidor lo guarda en S3 y encola el procesamiento asíncrono. Máximo 200 MB.
**Auth requerida:** Sí

**Parámetros de request (multipart/form-data):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `video` | file | Sí | Archivo de video (máx. 200 MB) |

**Response exitoso (200):**
```json
{
  "message": "Video recibido. Será procesado en breve."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 404 | El usuario no tiene perfil |
| 422 | Archivo faltante o supera 200 MB |

---

### DELETE /api/profiles/me/video

**Descripción:** Elimina el video de presentación del perfil del usuario autenticado. Borra el archivo de S3 y pone `video_url` y `video_processed` en `null`/`false`.
**Auth requerida:** Sí

**Parámetros de request:** Ninguno

**Response exitoso (200):**
```json
{
  "message": "Video eliminado."
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 404 | El usuario no tiene perfil |

---

## Matching

> Todos los endpoints de matching requieren autenticación (`Authorization: Bearer {token}`).

---

### GET /api/matching/explore

**Descripción:** Devuelve una cola de perfiles para explorar, filtrados y ordenados por un algoritmo de afinidad basado en intereses compartidos, intención, distancia y si el otro usuario ya dio super_like al viewer.
**Auth requerida:** Sí

**Parámetros de query:**
| Parámetro | Tipo | Requerido | Descripción |
|---|---|---|---|
| `limit` | integer | No | Cantidad de perfiles a retornar. Máximo 50. Por defecto 25 |

**Algoritmo de scoring (referencia):**
- +10 puntos por cada interés compartido
- +20 puntos si la intención coincide
- +5 puntos si el perfil está verificado
- +5 puntos si el perfil tiene video procesado
- +15 puntos si el candidato ya le dio super_like al viewer
- Penalización por distancia (km de distancia hasta -50 pts)
- Excluido si está fuera del rango de distancia configurado en preferencias

**Perfiles excluidos:** usuarios que el viewer ya swipeó, usuarios inactivos, usuarios que no completaron el onboarding.

**Response exitoso (200):**
```json
{
  "data": [
    {
      "id": "uuid",
      "display_name": "Jordan",
      "age": 27,
      "pronouns": ["elle/elle"],
      "gender_identities": ["no-binarie"],
      "orientations": ["pansexual"],
      "city": "Ciudad de México",
      "intention": "friendship",
      "is_verified": false,
      "has_video": true,
      "interests": ["cine", "yoga", "lectura"],
      "photos": [
        { "id": "uuid", "url": "https://...", "position": 1 }
      ]
    }
  ]
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |

---

### POST /api/matching/swipe

**Descripción:** Registra un swipe del usuario autenticado sobre otro perfil. Si ambos usuarios se dieron like o super_like mutuamente, se crea un match automáticamente.
**Auth requerida:** Sí

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `swiped_id` | UUID | Sí | ID del usuario sobre el que se hace el swipe |
| `direction` | string (enum) | Sí | `like`, `dislike`, o `super_like` |

**Response exitoso (200):**
```json
{
  "data": {
    "swiped": true,
    "matched": false,
    "match_id": null
  }
}
```

Si hay match:
```json
{
  "data": {
    "swiped": true,
    "matched": true,
    "match_id": "uuid"
  }
}
```

**Notas:**
- No se puede swipear el propio perfil.
- Un `dislike` nunca genera match.
- Un match se crea cuando ambos usuarios tienen swipes de tipo `like` o `super_like` mutuamente.

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 422 | `swiped_id` inválido, usuario no existe, intentando swipar el propio perfil, `direction` inválido |

---

### GET /api/matching/matches

**Descripción:** Devuelve todos los matches del usuario autenticado, ordenados del más reciente al más antiguo, con información básica del otro usuario.
**Auth requerida:** Sí

**Parámetros de request:** Ninguno

**Response exitoso (200):**
```json
{
  "data": [
    {
      "id": "uuid",
      "matched_at": "2025-06-01T12:00:00Z",
      "other_user": {
        "id": "uuid",
        "display_name": "Jordan",
        "age": 27,
        "is_verified": false,
        "city": "Monterrey",
        "intention": "community",
        "photo": "https://bucket.s3.amazonaws.com/photos/profiles/uuid/foto.jpg"
      }
    }
  ]
}
```

**Notas:** `other_user` es `null` si el otro usuario eliminó su perfil.

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |

---

### GET /api/matching/preferences

**Descripción:** Devuelve las preferencias de matching actuales del usuario autenticado. Si no existen, se crean con valores por defecto.
**Auth requerida:** Sí

**Parámetros de request:** Ninguno

**Response exitoso (200):**
```json
{
  "data": {
    "age_min": 18,
    "age_max": 99,
    "max_distance_km": 50,
    "intentions": null,
    "gender_identities": null,
    "orientations": null,
    "verified_only": false,
    "has_video_only": false
  }
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |

---

### PUT /api/matching/preferences

**Descripción:** Actualiza las preferencias de matching del usuario autenticado. Todos los campos son opcionales.
**Auth requerida:** Sí

**Parámetros de request (JSON):**
| Campo | Tipo | Requerido | Descripción |
|---|---|---|---|
| `age_min` | integer | No | Edad mínima de candidatos (18–99, debe ser <= `age_max`) |
| `age_max` | integer | No | Edad máxima de candidatos (18–99, debe ser >= `age_min`) |
| `max_distance_km` | integer | No | Distancia máxima en km (1–300) |
| `intentions` | array de string\|null | No | Filtrar por intención: `partner`, `friendship`, `community`, `mentorship` |
| `gender_identities` | array de string\|null | No | Filtrar por slugs de identidades de género |
| `orientations` | array de string\|null | No | Filtrar por slugs de orientaciones sexuales |
| `verified_only` | boolean | No | Solo mostrar perfiles verificados |
| `has_video_only` | boolean | No | Solo mostrar perfiles con video procesado |

**Response exitoso (200):**
```json
{
  "data": {
    "age_min": 22,
    "age_max": 35,
    "max_distance_km": 20,
    "intentions": ["friendship", "community"],
    "gender_identities": null,
    "orientations": null,
    "verified_only": false,
    "has_video_only": true
  }
}
```

**Códigos de error:**
| Código | Cuándo |
|---|---|
| 401 | Sin token |
| 422 | `age_min` > `age_max`, valores fuera de rango, valores de enum inválidos |
