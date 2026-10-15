# Estándar de vistas (UI)

Definición del formato estándar de una vista de la plataforma XP: estructura
de página, pestañas, tarjetas de resumen, filtros, estados de carga/vacío/
error/permiso y pie de formulario. Es obligatorio para vistas nuevas y para
cualquier vista que se toque (ver regla en `AGENTS.md`).

Objetivo: que todas las vistas se comporten igual (mismos estados, mismos
botones, misma semántica de URL) sin reimplementar el HTML en cada módulo.

## 1. Dónde vive

| Pieza | Fichero |
| --- | --- |
| Componentes de estructura de vista | `public/js/ui/view.js` |
| Componentes de contenido (cabecera, estados, tablas, resumen, paginación) | `public/js/ui/render.js` |
| Utilidades DOM (`escapeHtml`, etc.) | `public/js/ui/dom.js` |
| Modales (`modalShell`, `field`, `grid`, `modalFooter`, errores de formulario, Esc/Enter/foco) | `public/js/ui/modal.js` |
| Acciones compartidas (`handleActionError`, `can`, claves idempotencia) | `public/js/ui/actions.js` |
| Selector de permisos maestro-detalle (`permissionPicker`) | `public/js/ui/permission-picker.js` |
| CSS de pestañas (`.tabs`, `.tab`, `.tab.is-active`) | `public/css/components.css` |

Orden de carga en `public/index.php`: `core/i18n.js` (expone `t` global) →
`ui/dom.js` → `ui/render.js` → `ui/view.js` → `ui/modal.js` → `ui/actions.js` →
`ui/permission-picker.js`. Si se añade un componente en `ui/view.js`, hay que
incluirlo en `UI_FILES` de los smoke (`tools/smoke/smoke-*.mjs`):
`['ui/dom.js','ui/render.js','ui/view.js','ui/modal.js','ui/actions.js']`
(y `ui/permission-picker.js` en los smoke que montan modales con el picker).

## 2. Esqueleto de una vista

```js
const TABS_MI_VISTA = ['create', 'list'];

function buildTemplate() {
    const panels = [];

    if (canCreate()) {
        panels.push({ id: 'create', html: `<form data-mi-form>...</form>` });
    }

    panels.push({
        id: 'list',
        html: `
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4" data-mi-summary></div>
        ${UI.filtersCard(`...campos con [data-filter]...`, {
            cols: 4,
            applyLabel: t('mi.modulo.filters.apply'),
            clearLabel: t('mi.modulo.filters.clear'),
            clearTitle: t('mi.modulo.filters.clear_title')
        })}
        <div class="card p-0">
            <div class="table-wrapper" data-mi-table></div>
            <div data-mi-pagination class="pagination"></div>
        </div>`
    });

    return UI.viewShell({
        title: t('mi.modulo.title'),
        subtitle: t('mi.modulo.subtitle'),
        tabs: [
            { id: 'create', label: t('mi.modulo.create.title'), show: canCreate() },
            { id: 'list', label: t('mi.modulo.list_title') }
        ],
        activeTab: state.tab,
        panels: panels
    }) + `<div data-modal-root></div>`;
}

const MiModulo = {
    init(params, targetContainer) {
        container = targetContainer;
        if (!container) return;
        if (!canView()) { renderPermissionDenied(); return; }

        state.tab = UI.tabFromParams(params, TABS_MI_VISTA, canCreate() ? 'create' : 'list');
        container.innerHTML = buildTemplate();
        bindEvents();
        renderAll();          // pinta el estado inicial de ambos paneles
        if (canCreate()) loadCatalogs();
        loadList(1);          // datos eager: los dos paneles se cargan en init
    },

    onActivate(params) {
        if (!container) return;
        if (!canView()) { renderPermissionDenied(); return; }

        if (!container.querySelector('[data-mi-table]')) {  // reconstrucción
            container.innerHTML = buildTemplate();
            bindEvents();
        }
        const tab = UI.tabFromParams(params, TABS_MI_VISTA, state.tab);
        if (tab !== state.tab) applyTab(tab);

        loadList(state.pagination.page || 1);              // refresca al volver
    },

    destroy() { /* removeEventListener + innerHTML = '' + reinicio de state */ }
};
```

## 3. Pestañas

Patrón común (nacido en `modules/users/users.js`, ahora en `ui/view.js`):

- Barra: `<div class="tabs mb-4" role="tablist" data-tabs>` con botones
  `type="button" role="tab" data-action="tab" data-tab="id"`; CSS en
  `public/css/components.css`.
- Contenido: cada bloque vive en `<div role="tabpanel" data-panel="id">`. El
  panel inactivo lleva la clase `hidden` (no se oculta con `display:none` en
  línea y no se elimina del DOM).
- **Ambos paneles se montan a la vez** y los datos se cargan en `init`. Cambiar
  de pestaña sólo alterna la visibilidad (`UI.applyTabState`) y refresca los
  datos del panel de destino (`switchTab`); así el DOM de los smoke sigue
  localizable con `querySelector` en cualquier estado.
- La pestaña activa se guarda en `state.tab`, se aplica con
  `UI.applyTabState(container, tab)` y se refleja en la URL con
  `UI.syncTabParam(tab)` (`?tab=id` sobre el hash, `history.replaceState`, no
  dispara `hashchange`). `UI.tabFromParams(params, ids, fallback)` la restaura
  en `init` y `onActivate`.
- Si una pestaña no aplica (permiso), su botón no se pinta (`show: false` en
  `tabs` y panel ausente); con menos de dos botones visibles
  `UI.tabsBar` no devuelve nada y la vista queda monolítica.
- Tras crear un registro con éxito se cambia a la pestaña de listado con
  `applyTab('list')` (**sin** recargar: la propia operación ya recarga los
  paneles).
- El handler de clics reparte `case 'tab': switchTab(btn.dataset.tab);`.

Etiquetas de pestaja: reutilizar el título de la sección que ya existe en
i18n (p. ej. `inventory.picking.start.title` / `inventory.picking.operations_title`);
no crear claves `tabs.*` nuevas si ya hay un título equivalente.

## 4. Componentes de contenido (`ui/render.js`)

| Componente | Uso |
| --- | --- |
| `UI.pageHeader({title, subtitle, actionsHtml})` | Cabecera; `viewShell` ya la invoca. |
| `UI.summaryCards(root, [{label, value}])` | Tarjetas de resumen (4 por fila). |
| `UI.emptyState({title, message})` | Vacío de un contenedor. Escapa `title`/`message`: **no** sirve para HTML. |
| `UI.loadingState()` | Spinner centrado de lista/modal. |
| `UI.estadoBadge(valor, catalogo, colorDefecto)` | Badge de estado. |
| `UI.tableShell(columnas, filas)` | Tabla con cabecera traducida. |
| `UI.renderPagination(root, {pagination, shown, label})` | Info + botones `data-page`. |

## 5. Componentes de vista (`ui/view.js`)

| Componente | Uso |
| --- | --- |
| `UI.viewShell({title, subtitle, actionsHtml, tabs, activeTab, panels})` | Envoltorio completo: `max-w-7xl` + cabecera + barra + paneles. No incluye `[data-modal-root]`: lo monta el módulo. |
| `UI.tabsBar(tabs, activeId)` / `UI.tabPanel(id, active, html)` | Piezas sueltas si no se usa `viewShell`. |
| `UI.applyTabState(root, activeId)` | Sincroniza botones/paneles con la pestaña activa. |
| `UI.tabFromParams(params, ids, fallback)` | Lee `?tab=` de la ruta. |
| `UI.syncTabParam(tab)` | Escribe `?tab=` en el hash (reemplazo, sin navegación). |
| `UI.filtersCard(fieldsHtml, {applyLabel, clearLabel, clearTitle, cols, applyAction, clearAction})` | Tarjeta de filtros con Aplicar (`btn-secondary`) y Limpiar (`btn-ghost`) en su propia fila, `type="button"`. |
| `UI.formFooter({submitLabel, submitAction, hintHtml})` | Pie de formulario: pista de `Idempotency-Key` (`[data-key-hint]`) + botón de envío. |
| `UI.errorState({title, message, retryAction, retryLabel})` | Error de carga con botón de reintento. |
| `UI.deniedState({title, message, ctaHref, ctaLabel})` | Pantalla "sin permiso" con CTA al dashboard. |
| `UI.rowActions({primary, groups, ariaLabel})` | Acciones de fila (ver §4.1). Devuelve `''` si no hay ninguna opción tras filtrar permisos en el módulo. |

### 4.1 Acciones de fila (`UI.rowActions`)

Patrón estándar de acciones de una tabla (regla en `AGENTS.md`):

- **Acción principal** visible (la más frecuente, normalmente Editar) como
  `btn btn-ghost btn-sm` discreto.
- **Resto en un menú de tres puntos** (`⋮`) a la derecha, con grupos
  separados por `dropdown-divider` y la acción destructiva al final, en rojo
  (`.dropdown-item.danger`), siempre con confirmación.
- **Columna** `col-actions` compacta, alineada a la derecha; sin scroll
  horizontal de la tabla en escritorio (1366px y 1920px). En pantallas
  estrechas el menú sigue funcionando (se posiciona dentro del viewport).
- **Permisos**: cada opción se pinta sólo si el usuario tiene el permiso
  (se filtra en el módulo antes de llamar); si no queda ninguna, no se pinta
  ni el menú (`rowActions` devuelve `''`).
- **Accesibilidad**: abre con clic o teclado (`aria-haspopup="menu"`,
  `aria-expanded`), cierra con Esc y clic fuera, foco gestionado (al abrir
  va al primer ítem; al cerrar con Esc vuelve al disparador). El menú se
  reposiciona para no quedar cortado por el contenedor de la tabla.
- **Eventos**: delegación global en `document` (una sola vez al cargar
  `ui/view.js`); el módulo no necesita bind de abrir/cerrar menú, sólo de
  sus `data-action` (ya en `handleClick`).
- **CSS**: `.row-actions`, `.row-actions-trigger` y `.dropdown-menu[data-row-menu]`
  en `public/css/components.css`. Las celdas de texto largo usan
  `truncate-cell` (con `title`) o `nowrap-cell` (chip/último acceso).

Ejemplo:

```js
UI.rowActions({
    primary: canEdit()
        ? { action: 'open-edit', id: u.id, label: t('users.actions.edit') }
        : null,
    groups: [
        [
            { action: 'open-roles', id: u.id, label: t('users.actions.roles') },
            { action: 'open-temp-user', id: u.id, label: t('users.actions.temp_view') }
        ],
        [
            { action: 'open-status', id: u.id, label: t('users.actions.status') },
            { action: 'open-reset', id: u.id, label: t('users.actions.reset') }
        ],
        [
            { action: 'open-delete', id: u.id, label: t('users.actions.delete'), danger: true }
        ]
    ],
    ariaLabel: t('users.columns.actions')
});
```


### 4.2 Selector de permisos (`UI.permissionPicker`)

Componente `public/js/ui/permission-picker.js` (v1.13.25) para elegir los
permisos de un rol dentro de un modal, con patrón maestro-detalle:

- **Uso**: `UI.permissionPicker.mount({toolbar, panel, catalog, selected,
  readonly, granted, baseRoles, onBaseRole, onChange})`. `catalog` es el
  resultado de `GET /permissions` (campos `identificador`, `modulo`,
  `recurso`, `criticidad`, `requiere_mfa`, `descripcion`, `nombre`). El
  estado queda en `panel.__pp`; la selección se lee con
  `UI.permissionPicker.getSelected(panel)` y se fija con `setSelected`.
- **Estructura**: a la izquierda los módulos con contador
  seleccionados/total (en pantallas estrechas, un `<select>`); a la derecha
  los permisos del módulo activo agrupados por recurso, con checkbox,
  código, descripción y badge de riesgo.
- **Modal** (`size: 'pp'` → `.modal-pp`, 1100px × 85vh): cabecera y pie
  fijos; sólo los paneles del picker hacen scroll. El body del modal se
  vuelve flex (`.modal-pp .modal-body`) y el contenedor del picker usa
  `.pp-mount`.
- **Campos del formulario en el mismo modal**: Nombre (y toolbar del picker)
  en `.pp-head-row`; **Descripción siempre en su propia fila** (nunca al par
  de otro campo) con `controlClass: 'textarea-autogrow'` (crece con el
  contenido, sin scrollbars ni asa de redimensionar).
- **Escalada**: `granted` (códigos del actor vía `AuthState.getPermissions()`)
  deshabilita con tooltip los permisos no poseídos; si es `null` no se
  bloquea nada (mismo contrato vacío que `UI.can`).
- **Sólo lectura**: `readonly: true` (rol BUILT_IN o sin `roles.role.edit`)
  pinta todo deshabilitado y sin toolbar.
- **Extras de UX**: buscador por código/descripción, filtro de riesgo,
  "solo seleccionados", "Basar en" otro rol (`onBaseRole(id)` → códigos),
  "Solo lectura" (sólo `.view`), "Limpiar", auto-marcar `.view` al marcar
  crear/editar/eliminar del mismo recurso (aviso breve, sólo UI),
  indeterminate en cabeceras de módulo/recurso, foco en el buscador al
  montar y resumen `N permisos seleccionados (M criticos)` en el pie del
  modal (aviso si hay críticos).
- **CSS**: `.pp-*` en `public/css/components.css`. **i18n**: claves
  `ui.picker.*` y `roles.perms.summary` / `roles.perms.critical_alert` en los
  4 locales.
- **Referencia de adopción**: `public/js/modules/roles/roles.js` (modales
  crear/editar/permisos).


## 6. Estándar de modales de formulario (M0, v1.13.51)

Patrón obligatorio para todo modal que contenga un formulario. Los comportamientos
(Esc, Enter, foco) viven en `public/js/ui/modal.js` a nivel de `document` y
aplican también a los modales preexistentes que aún no se han migrado.

### 6.1 Composición

- Envoltorio con `UI.modalShell({title, size, bodyHtml, footerHtml})` y montaje
  con `UI.mountModal(root, html)` sobre el `[data-modal-root]` del módulo.
  No se escribe el HTML del modal a mano en el módulo.
- El overlay lleva siempre `data-modal` (base de Esc/Enter/foco); la cabecera
  tiene su `×` con `data-action="close-modal"`.

### 6.2 Tamaños

| Tamaño | Clase / variable | Ancho | Uso |
| --- | --- | --- | --- |
| `sm` | `.modal-sm` / `--modal-width-sm` | 480px (30rem) | Confirmaciones y detalles cortos. |
| `md` (defecto) | `.modal-md` / `--modal-width` | 640px (40rem) | Formularios estándar. |
| `lg` | `.modal-lg` / `--modal-width-lg` | 1000px (62.5rem) | Formularios anchos. |
| `xl` | `.modal-xl` / `--modal-width-xl` | 1200px (75rem) | Modales con tablas dentro. |
| `pp` | `.modal-pp` | 1100px × 85vh | Selector de permisos (§4.2). |

Variables en `public/css/themes.css`, clase en `public/css/components.css`.
En pantallas ≤640px todos los modales van a ancho completo (regla responsive).
Cabecera y pie fijos, sólo `.modal-body` hace scroll; con `max-height: 90vh`
el modal siempre cabe en 1366×768.

### 6.3 Pie estándar (`UI.modalFooter`)

```js
footerHtml: UI.modalFooter({
    hintHtml: '<span data-key-hint></span>',   // opcional, a la izquierda
    cancelLabel: t('ui.cancel'),               // por defecto
    submitLabel: t('mi.modulo.actions.save'),
    submitAction: 'submit-save',
    disabled: state.sending                    // o true con error bloqueante
})
```

- Orden: hint (`mr-auto`) → Cancelar (`btn-secondary`) → primario
  (`btn-primary`, o `btn-danger` con `danger: true`).
- Cancelar es `type="button"` con acción `close-modal`: descarta el modal
  igual que la `×` de la cabecera, pero su etiqueta es "Cancelar"
  (`t('ui.cancel')`); "Cerrar" queda para la cabecera y para modales de solo
  lectura (detalle).
- El primario **no** lleva `type`: fuera de `<form>` lo maneja el `data-action`
  del módulo y dentro de `<form>` manda el listener de `submit`.
- El primario lleva `data-modal-send`, que los helpers de envío de cada módulo
  deshabilitan con `state.sending` (petición en curso); `disabled: true` en
  `modalFooter` cubre el arranque y estados bloqueantes.

### 6.4 Campos (`UI.field`, `UI.grid`)

- Etiqueta arriba (`form-label`), asterisco `required`, control, error inline
  (`data-error`, visible con `showFieldError`) y ayuda debajo (`form-hint`).
- Textarea: clase `input textarea` y **mínimo 4 filas**; con
  `controlClass: 'textarea-autogrow'` crece con el contenido (§4.2).
- `UI.grid(html)` a **una columna por defecto**; dos columnas sólo para un par
  de campos cortos relacionados (p. ej. código + tipo).
- **Sin columnas fantasma** (M2, v1.13.53): un `UI.grid` con un solo hijo se
  rinde como campo a ancho completo (no media pantalla con la mitad vacía);
  `grid(…, 2)` sólo con las dos celdas llenas y la misma altura interna
  (sin `mb-0` asimétrico).
- **Elección binaria visible** (M2, v1.13.53): `.segmented > label.segment`
  envuelve los radios nativos (mismo `name`, `valor` y handler `change`);
  sólo cambia la presentación. El resto se apila a una columna con el ritmo
  uniforme de `form-group` (1rem).
- **Cabecera con toolbar** (`.pp-head-row`, M3, v1.13.54): los hijos
  `.form-group` anulan su `margin-bottom` (`.pp-head-row > .form-group`);
  con `align-items: end` ese margen subía el campo 16px y su línea base no
  coincidía con la del toolbar (Basar en + botones).

### 6.5 Teclado y foco (global, en `ui/modal.js`)

- **Esc** cierra el modal superior (fase de captura): respeta un menú de fila
  abierto (lo cierra `view.js`), el desplegable nativo de un `<select>` y un
  combobox con `aria-expanded="true"`.
- **Enter** envía: dentro de un modal, desde `input`/`select` pulsa el botón
  primario del pie. No envía desde `textarea`, desde `input[type=search]`,
  desde un campo con `aria-expanded="true"` o `data-no-enter-submit`, ni
  dentro de un `<form>` (ahí rige el submit nativo).
- **Foco** en el primer campo al abrir por primera vez; un re-render del modal
  no re-enfoca y si el módulo ya enfocó algo (p. ej. el buscador del picker)
  no se le roba.
- **Contrato para componentes con sugerencias**: ver §6.6 (combobox M1) —
  `aria-expanded="true"` mientras la lista esté visible y `preventDefault()`
  en el Enter que elige una sugerencia.
- El primario deshabilitado (`disabled`/`sending`) no recibe el Enter
  (el clic programático sobre un botón deshabilitado no hace nada).

### 6.6 Combobox de modal (M1, v1.13.52)

- Componente compartido en `ui/modal.js`: `UI.combobox({name, label,
  placeholder, hint, required})` genera el HTML (label + `input[role=combobox]`
  con `aria-expanded`/`aria-controls`/`aria-autocomplete` + `[data-combo-chips]`
  y `ul[role=listbox]` dentro de `.combo-control` + `span[data-error]` + hint)
  y `UI.bindCombobox(root, {fetch, onSelect, isExcluded, loadingLabel,
  emptyLabel, errorLabel, debounceMs})` lo activa. Los chips van DENTRO de
  `.combo-control` para que la lista desplegable caiga bajo el conjunto y no
  tape los botones de quitar. La lista es ABSOLUTA (no altera la altura del
  modal: cerrarla en un blur no redimensiona el modal y no se pierden clics
  en curso); si el campo queda bajo en el body del modal, el scroll del body
  o la navegación con teclado (`scrollIntoView`) revelan el resto.
- `fetch(term)` devuelve `[{value, label}]` (o lanza; el componente pinta el
  `errorLabel`). Búsqueda con debounce (250 ms), respuestas fuera de secuencia
  descartadas y la lista sólo se repinta si el input sigue conectado y con el
  foco. Primera opción resaltada por defecto (`aria-activedescendant`).
- Teclado: flechas recorren la lista; **Enter con la lista visible se consume
  aquí** (elige la resaltada; con la lista cerrada el Enter global del modal
  envía normalmente); **Esc cierra primero la lista** (`aria-expanded="true"`
  salta al modal) y el modal sólo se cierra en la segunda Esc.
- La selección vive en el estado del módulo: `onSelect(item)` añade al array
  y el módulo pinta sus chips (`.chip` con `data-action="combo-remove"`) con
  render dirigido a `[data-combo-chips]`, sin re-render del cuerpo. El
  componente excluye ya elegidos vía `isExcluded` y al elegir limpia el input
  (`skipFocusLoad`: reenfocar no recarga la lista).
- El módulo enfoca `[data-combo-input]` tras montar (el observer de §6.5 no
  re-enfoca porque la raíz ya estaba en `openedRoots`); ese foco dispara la
  primera carga con término vacío.
- Pie con `UI.modalFooter` (Cancelar vuelve al contexto anterior, no cierra).

### 6.7 Errores y verificación

- Errores de campo/formulario: `showFieldError`, `showFormError`,
  `clearErrors` (mismo contrato `data-error` que ya usaban todos).
- Smoke: `node tools/smoke/smoke-modales-m0.mjs` (tamaños, pie, Esc, Enter,
  foco) y `node tools/smoke/smoke-m1-add-member.mjs` (combobox de añadir
  miembro). `node --check public/js/ui/modal.js`.

## 6.7 Estilo iOS y marca del tenant (v1.14.0)

- La capa `public/css/ios.css` se carga despues de `components.css` y sólo refina presentación; nada de `backdrop-filter` ni superficies translúcidas.
- El color de acción es siempre `rgb(var(--color-primary))`: `TenantBranding` lo sustituye por el color de marca del tenant, así que no se usan azules literales.
- Fechas de listados con `UI.formatDate(valor)` o `UI.formatDateTime(valor)`; importes con `UI.formatMoney(n)`. No usar `toLocaleDateString` ni pintar la cadena cruda de la BD.

## 7. Reglas de contenido

- **Filtros**: campos con `data-filter="nombre"` (uno por campo), botones
  `data-action="apply-filters"` / `clear-filters` y lectura genérica
  `container.querySelectorAll('[data-filter]').forEach(...)`. La tarjeta se
  construye con `UI.filtersCard`.
- **Paginación**: `UI.renderPagination` + `data-page` en los botones y
  `case` genérico en el handler. No usar selectores propios
  (`data-page-xxx`, `data-action="apply-xxx-filters"`).
- **Estados de una lista**: `state.xxxLoading` → `UI.loadingState`;
  `state.listError` → `UI.errorState` con `retryAction` y recarga de la página
  actual; vacío → `UI.emptyState`; sin permiso → `UI.deniedState`.
- **Resumen**: siempre `UI.summaryCards` (nada de tarjetas `card p-3` escritas
  a mano).
- **Pie de formulario**: siempre `UI.formFooter`; `renderKeyHint()` del módulo
  rellena `[data-key-hint]`.
- **Errores de formulario y modales**: `ui/modal.js`
  (`field`, `grid`, `modalFooter`, `showFieldError`, `showFormError`,
  `clearErrors`); composición, pie y teclado según §6.
- **i18n**: todo texto visible sale de `t()`; los valores de enum que se
  muestran al usuario (tipo de envío, estado de ítem…) se traducen con claves
  propias (`inventory.shipments.types.*`) y tienen fallback al valor crudo.
  `php bin/check-i18n.php` debe quedar sin errores nuevos.

### Excepciones admitidas

- `UI.deniedState` inserta `title`/`message` **sin escapar** porque las
  traducciones de permiso contienen `<code>{permiso}</code>`. Lo mismo vale
  para el aviso "sin acceso a envíos" dentro de
  `[data-en-ruta-table]` (`inventory.deliveries.permission.en_ruta_body`),
  que se escribe a mano en el módulo y se documenta ahí mismo.
- Pistas vacías *dentro* de un formulario (p. ej. "Elige un lote para ver sus
  unidades" en `shipments.js`) pueden quedarse como `<div class="empty-state">`
  con un solo `<p>`: son texto de apoyo, no un estado de contenedor.

## 8. Checklist de adopción

1. `node --check` en el módulo tocado.
2. Estructura con `UI.viewShell` (o `pageHeader` + barra de pestañas propia).
3. `state.tab` + `tabFromParams` en `init` y `onActivate`.
4. Resumen con `summaryCards`, filtros con `filtersCard` + `data-filter`,
   paginación con `renderPagination` + `data-page`.
5. Estados de lista con `loadingState` / `emptyState` / `errorState` /
   `deniedState`.
6. Pie de formulario con `formFooter`.
7. Modales con `modalShell` + `modalFooter` y campos de `field`/`grid` (§6).
8. Sin selectores ni botones propios que dupliquen componentes.
9. Textos en i18n (4 idiomas) y `php bin/check-i18n.php`.
10. Smoke: `UI_FILES` con `ui/view.js` y `node tools/smoke/smoke-*.mjs` en verde.

## 9. Estado de adopción (auditoría, v1.13.11)

Sólo se modificaron las tres vistas de este sprint; el resto es informe.

| Módulo | Estado | Desviaciones |
| --- | --- | --- |
| `inventory/picking` | Aplicado | Tabla construida a mano en vez de `UI.tableShell` (aceptado, ver §4). |
| `inventory/shipments` | Aplicado | Ídem. Avisos vacíos dentro del formulario con `empty-state` propio (excepción §7). |
| `inventory/deliveries` | Aplicado | Ídem. Aviso "sin acceso a envíos" escrito a mano (excepción §7). |
| `users` | Parcial | Tiene pestañas y `switchTab` pero **sin estado en la URL** (`?tab=`) y con shell/filtros propios; sin `summaryCards`. Es el precedente del patrón, pendiente de migrar a `viewShell`. |
| `inventory/counts`, `inventory/incidents`, `inventory/movements` | Parcial | Ya usan `summaryCards`, `emptyState`, `loadingState`, `renderPagination`, pero shell propia (`pageHeader`), `DENIED_TEMPLATE` a mano y tarjeta de filtros propia. |
| `inventory/reservations` | Parcial | Componentes de contenido compartidos; sin pantalla de permiso propia ni pestañas. |
| `groups`, `roles` | Parcial | `emptyState`, `loadingState`, `tableShell`, modales compartidos; sin `summaryCards` ni `filtersCard` (no tienen filtros de lista) y sin pantalla de permiso. |
| `inventory/locations`, `inventory/products`, `inventory/stock`, `inventory/returns`, `inventory/receiving`, `audit/logs` | Sin aplicar | `pageHeader` compartido, pero estados vacíos, spinners, filtros y paginación escritos a mano; `returns` y `logs` con `DENIED_TEMPLATE` propio. |
| `dashboard`, `profile`, `auth/login` | N/A | Sin listados: `pageHeader` y spinners propios (login es una pantalla propia). |

Otras desviaciones detectadas (fuera de alcance, sólo informe):

- `resources/locales/pt.json` y `fr.json` contienen grandes bloques sin
  traducir (texto español heredado). No se tocaron.
- `public/js/modules/profile/profile.js` tiene literales en español fuera de
  `t()` (líneas 22 y 70): `php bin/check-i18n.php` sale con esos 2 errores
  preexistentes; las claves y la paridad de los 4 idiomas sí están en verde.
- Avisos de claves sin uso `theme.*` en `check-i18n` (preexistentes, no
  fatales).
