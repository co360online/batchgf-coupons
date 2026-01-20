README
Actúa como un desarrollador senior de WordPress especializado en Gravity Forms (GF) y el Add-On Coupons.
Necesito que desarrolles un plugin completo para WordPress que permita GENERAR CUPONES EN MASA para Gravity Forms Coupons Add-On, y gestionarlos desde el admin.

CONTEXTO
- Sitio WordPress con Gravity Forms instalado y activo.
- Está instalado y activo el Add-On oficial: Gravity Forms Coupons.
- Los cupones se usan en formularios de inscripción con pagos (Product fields / Total / Payment Add-On).
- Quiero evitar crear cupones uno a uno.

OBJETIVO DEL PLUGIN
1) Generar N cupones automáticamente para un formulario de Gravity Forms (form_id) usando el sistema del Add-On Coupons (no inventar un sistema paralelo).
2) Permitir configurar:
   - Tipo de descuento: importe fijo o porcentaje.
   - Valor del descuento: number (ej. 10 o 15.50).
   - Código: prefijo opcional (ej. EVENTO-) + parte aleatoria, o lista incremental.
   - Longitud de la parte aleatoria (default 8).
   - Mayúsculas/numéricos (sin caracteres ambiguos 0/O/1/I).
   - Límite de uso por cupón: 1 por defecto, configurable, y opción "ilimitado".
   - Fecha de expiración:
       - Fecha concreta (selector fecha)
       - O “sin expiración” (infinite)
   - “Stackable / combinable” si el Add-On lo soporta (si no, ocultar o ignorar).
   - Opcional: notas o etiqueta interna (ej. campaña) para filtrar luego.

3) Vista de administración:
   - Nuevo menú en wp-admin: “GF Bulk Coupons”
   - Submenús:
     a) “Generar” (formulario de generación masiva)
     b) “Cupones” (listado / gestión)

4) Pantalla “Generar”:
   - Selector de formulario (lista todos los formularios publicados de Gravity Forms con id y título).
   - Campos: tipo, valor, número de cupones a generar, prefijo, longitud, límite uso, expiración, etiqueta.
   - Validación server-side y mensajes admin_notices.
   - Botón “Generar cupones”.
   - Tras generar: mostrar resumen:
       - Cantidad generada
       - Form ID
       - Parámetros usados
       - Tabla con los nuevos códigos (con botón “Copiar” y/o descarga CSV)
   - Incluir botón “Exportar CSV” (para los cupones recién generados) y también permitir exportación desde el listado.

5) Pantalla “Cupones”:
   - List table estilo WordPress (WP_List_Table) con:
       - Código
       - Form ID + nombre del formulario
       - Tipo (percentage/flat)
       - Valor
       - Límite uso
       - Usos (si es posible obtenerlo)
       - Estado (Activo / Expirado / Agotado / Sin expiración)
       - Fecha expiración (o “—”)
       - Fecha creación
       - Etiqueta/campaña (si se implementa)
   - Filtros:
       - Por formulario
       - Por estado (activo/expirado/usado up)
       - Búsqueda por código
   - Acciones:
       - Exportar filtrados a CSV
       - (Opcional) Borrar cupones seleccionados (con confirmación y capability check)
   - Paginación.

6) “Cupones usados” (si es viable):
   - Intentar mostrar el conteo de usos por cupón.
   - Si el Add-On guarda usos en una tabla propia, usarla.
   - Si no existe tracking nativo, calcular usos consultando GF entries del form_id buscando el campo de cupón:
       - Detectar el/los campos “Coupon” del formulario usando GF API (GFFormsModel / GF_Field_Coupon).
       - Contar entradas donde el valor del campo coincide con el código.
       - NOTA: esto puede ser pesado; entonces:
           - Ofrecer opción de “calcular usos bajo demanda” y cachear en transients por X minutos
           - O mostrar “—” si no se puede determinar sin impacto.

7) Integración con el Add-On Coupons:
   - Usar la API/clase del Add-On si existe (por ejemplo GFCoupons, gf_coupons(), o métodos documentados).
   - NO insertar directamente en la base de datos salvo que no exista API pública.
   - Si se recurre a BD:
       - Detectar nombre de tabla con $wpdb->prefix
       - Insertar con $wpdb->insert usando formatos seguros
       - Mantener compatibilidad con la estructura del plugin GF Coupons.
   - Antes de operar, verificar:
       - Gravity Forms activo
       - GF Coupons Add-On activo
       - Versiones mínimas (si aplica)
     Si falta algo, mostrar aviso en admin.

8) Seguridad / Calidad:
   - Capability: solo admins o manage_options.
   - Nonces en todos los formularios y acciones (export, delete).
   - Sanitización/validación estricta de inputs.
   - Manejo de errores con mensajes claros.
   - Preparar el plugin para traducción (textdomain).
   - Código en PHP 7.4+ compatible (ideal 8.0+).
   - Estructura de plugin limpia:
       - main plugin file
       - /includes/admin-pages.php
       - /includes/class-bulk-generator.php
       - /includes/class-coupons-list-table.php
       - /includes/export.php
   - No usar frameworks; solo WP y GF.

9) Generación de códigos:
   - Deben ser únicos por form_id (evitar duplicados con los existentes).
   - Implementar bucle de generación con verificación de colisión (consulta rápida).
   - Permitir prefijo + random.
   - Default: random seguro (wp_generate_password) pero solo alfanumérico sin símbolos.

10) Exportación CSV:
   - UTF-8 con BOM opcional (para Excel).
   - Columnas mínimas:
       code, form_id, form_title, type, amount, usage_limit, expiration, created_at, campaign, uses
   - Descargar con headers correctos (admin-ajax o admin-post).
   - Permitir exportar: “recién generados” y “filtrados del listado”.

11) UX:
   - UI en admin simple, con estilos WP.
   - Mostrar spinner o mensaje mientras genera (si implementas AJAX).
   - Si N es grande (ej. > 2000), sugerir ejecución por lotes (AJAX en chunks) para evitar timeouts.
   - Implementa opción de “Generación por lotes” automáticamente si N > 500:
       - AJAX endpoint seguro con nonce
       - Genera en bloques de 200
       - Barra de progreso
       - Al final, muestra resumen y descarga CSV.

ENTREGABLES
- Código completo del plugin (todos los archivos).
- Instrucciones de instalación.
- Explicación breve de dónde se guardan los cupones y cómo se calcula “usos”.
- Notas sobre compatibilidad y rendimiento.

IMPORTANTE
- Si el Add-On Coupons no expone API pública suficiente, documenta claramente el plan B (tabla, campos).
- No inventes nombres de tablas sin verificarlos: detecta dinámicamente y maneja fallback.
- Evita romper la instalación si la estructura del Add-On cambia: añade comprobaciones defensivas.

Ahora genera el código del plugin.

# GF Bulk Coupons - Instrucciones y notas

## Instalación
1. Copia la carpeta del plugin en `wp-content/plugins/gf-bulk-coupons`.
2. Activa **GF Bulk Coupons** desde el panel de plugins.
3. Asegúrate de tener **Gravity Forms** y el **Add-On Gravity Forms Coupons** activos.

## Uso rápido
1. Ve a **GF Bulk Coupons > Generar**.
2. Selecciona el formulario y configura el tipo/valor de descuento.
3. Define cantidad, prefijo, longitud y expiración.
4. Si generas más de 500 cupones, el plugin usa generación por lotes (AJAX).
5. Exporta el CSV al final de la generación o desde **GF Bulk Coupons > Cupones**.

## Edición rápida en Gravity Forms
En la tabla de **Cupones** aparece la acción “Editar en Gravity Forms” para abrir la pantalla nativa de cupones del formulario correspondiente.

## Normalización automática del prefijo
Si el prefijo contiene caracteres no permitidos, el plugin lo normaliza a A-Z/0-9 en mayúsculas y muestra un aviso con el valor resultante. Si tras normalizar queda vacío, se detiene la generación con un error.

## Dónde se guardan los cupones
El plugin usa los **feeds** del Add-On oficial de Gravity Forms Coupons en la tabla `{$wpdb->prefix}gf_addon_feed` con `addon_slug` del Coupons Add-On. Para la etiqueta/campaña se crea una tabla auxiliar `{$wpdb->prefix}gfbcu_coupon_meta` con el mapeo código → campaña.

## Cómo se calcula el “uso” de cupones
Si el meta JSON del feed incluye un conteo (`usageCount` o similar), se muestra directamente. Si no existe, se calcula bajo demanda consultando las entradas del formulario y buscando valores en campos tipo **Coupon**. El resultado se cachea en transients por 10 minutos para evitar impacto en rendimiento.

## Compatibilidad y rendimiento
- Compatible con PHP 7.4+ y WordPress moderno.
- Se evita insertar directamente en BD salvo que no exista API pública; actualmente se usa inserción en tabla del Add-On con chequeos defensivos de columnas.
- La generación por lotes se activa automáticamente para cantidades grandes (chunk de 200).
