<?php
/**
 * check-i18n.php - Consistencia i18n (sprint v1.10.1; ampliado v1.10.3)
 *
 * Comprueba:
 *  1) Paridad de claves entre resources/locales/es.json (fuente) y en/pt/fr.
 *  2) Que todas las claves usadas en el codigo existan en es.json:
 *     - t('clave') / t("clave") en public/js/** y public/index.php
 *     - propiedades *Key: 'clave' (labelKey, titleKey, sectionKey...)
 *     - valores dentro de objetos *_KEYS = { ... }  (mapas de estado/tipo)
 *     - atributos data-i18n / data-i18n-aria / data-i18n-placeholder
 *  3) Que los placeholders {param} de cada clave coincidan entre idiomas.
 *  4) Aviso de claves de es.json sin uso (no fatal).
 *  5) Literales en espanol en public/js/** fuera de t() (sprint v1.10.3).
 *     Exenciones: core/i18n.js (diccionario embebido), enums (CODIGO),
 *     identificadores sueltos, ids de modulo, selectores [data-...], valores
 *     de atributos entre comillas, args de indexOf/match (mensajes del
 *     backend, que se mantienen en espanol por regla) y fragmentos de regex.
 *     Se escanean SOLO ficheros JS: public/index.php mezcla PHP de backend
 *     (cuyos mensajes se mantienen en espanol) con la plantilla del shell.
 *
 * Salida: 0 si todo OK; 1 si hay errores. Las claves sin usar en el codigo
 * se listan como AVISO (no afectan al exit code).
 *
 * Uso: php bin/check-i18n.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$localesDir = $root . '/resources/locales';
$locales = ['es', 'en', 'pt', 'fr'];

// ---------------------------------------------------------------------------
// Utilidades
// ---------------------------------------------------------------------------

function fail(string $msg): void
{
    fwrite(STDERR, "ERROR: $msg\n");
}

function flatten(array $node, string $prefix, array &$out): void
{
    foreach ($node as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            flatten($v, $key, $out);
        } else {
            $out[$key] = (string) $v;
        }
    }
}

function extractPlaceholders(string $value): array
{
    preg_match_all('/\{(\w+)\}/', $value, $m);
    $set = $m[1];
    sort($set);
    return $set;
}

function isCommentOrUseStrictLine(string $line): bool
{
    $t = trim($line);
    return $t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*');
}

/**
 * Recorre los ficheros JS/PHP buscando claves i18n usadas en el codigo.
 *
 * @return array<string, array{key: string, where: string}> claves usadas
 */
function collectUsedKeys(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public/js'));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
            $files[] = $file->getPathname();
        }
    }
    $files[] = $root . '/public/index.php';
    sort($files);

    $used = [];

    foreach ($files as $path) {
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $lines = preg_split('/\r\n|\n|\r/', (string) file_get_contents($path));
        $n = 0;
        $keysBlockDepth = 0; // >0 mientras estamos dentro de un objeto *_KEYS
        $keysBlockName = '';

        foreach ($lines as $line) {
            $n++;
            if (isCommentOrUseStrictLine($line)) {
                continue;
            }

            // Apertura de un objeto *_KEYS = {   (misma linea)
            if (preg_match('/\b[A-Za-z0-9_]*_KEYS\s*=\s*\{/', $line)) {
                $keysBlockDepth = 1;
                $keysBlockName = (string) preg_replace('/^.*\b([A-Za-z0-9_]*_KEYS)\s*=.*$/', '$1', $line);
            } elseif ($keysBlockDepth > 0) {
                $keysBlockDepth += substr_count($line, '{') - substr_count($line, '}');
            }

            $candidates = [];

            // t('clave') / t("clave")
            if (preg_match_all('/\bt\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $line, $m)) {
                foreach ($m[1] as $k) {
                    $candidates[] = $k;
                }
            }

            // Claves dentro de una llamada t(...) no anclada al inicio
            // (p. ej. t(LABEL_KEYS[p] || 'theme.toggle_default'))
            if (preg_match_all('/\bt\([^)]*?[\'"]([A-Za-z0-9_.]+)[\'"]/', $line, $m)) {
                foreach ($m[1] as $k) {
                    $candidates[] = $k;
                }
            }

            // labelKey: 'clave', titleKey: "clave", sectionKey: ...
            if (preg_match_all('/\b[A-Za-z0-9_]*[Kk]ey\s*:\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $line, $m)) {
                foreach ($m[1] as $k) {
                    $candidates[] = $k;
                }
            }

            // Dentro de un objeto *_KEYS: cualquier string con puntos
            if ($keysBlockDepth > 0 && $keysBlockName !== '') {
                if (preg_match_all('/[\'"]([a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+)[\'"]/', $line, $m)) {
                    foreach ($m[1] as $k) {
                        $candidates[] = $k;
                    }
                }
            }

            // data-i18n="clave" / data-i18n-aria / data-i18n-placeholder
            if (preg_match_all('/data-i18n(?:-aria|-placeholder)?="([A-Za-z0-9_.]+)"/', $line, $m)) {
                foreach ($m[1] as $k) {
                    $candidates[] = $k;
                }
            }

            foreach ($candidates as $k) {
                if (!isset($used[$k])) {
                    $used[$k] = ['key' => $k, 'where' => $rel . ':' . $n];
                }
            }
        }
    }

    return $used;
}

// ---------------------------------------------------------------------------
// Detector de literales en espanol fuera de t() (sprint v1.10.3)
// ---------------------------------------------------------------------------

/** Palabras espanolas tipicas que delatan un literal de UI sin t(). */
function i18nSpanishWords(): array
{
    return [
        'buscar', 'guardar', 'cancelar', 'eliminar', 'editar', 'crear', 'cerrar', 'abrir',
        'descargar', 'subir', 'cargar', 'cargado', 'aplicar', 'reiniciar', 'seleccionar',
        'confirmar', 'volver', 'siguiente', 'anterior', 'primero', 'ultimo', 'pagina',
        'filtro', 'filtros', 'sin datos', 'no hay', 'se ha', 'esta vacio', 'requerido',
        'obligatorio', 'invalido', 'no encontrado', 'cargando', 'guardado', 'eliminado',
        'actualizado', 'creado', 'usuario', 'usuarios', 'ubicacion', 'inventario',
        'auditoria', 'producto', 'productos', 'categoria', 'unidad', 'unidades', 'zona',
        'recepcion', 'almacen', 'stock actual', 'pedido', 'cliente', 'proveedor',
        'observaciones', 'comentario', 'cantidad', 'fecha', 'estado', 'tipo', 'nombre',
        'descripcion', 'motivo', 'resultado', 'accion', 'error al', 'denegado', 'permiso',
        'sesion', 'contrasena', 'idioma', 'tema', 'panel', 'bienvenid', 'salir',
        'todo listo', 'nada por', 'disponible', 'reservado', 'danado', 'bloqueado',
        'transito', 'preparacion', 'completado', 'pendiente', 'rechazad', 'aceptad',
        'abierta', 'cerrada', 'activa', 'inactiva', 'generar', 'imprimir', 'exportar',
        'importar', 'refrescar', 'actualizar', 'ver detalle', 'ver mas', 'ver menos',
        'mostrar', 'ocultar', 'ningun', 'ninguna', 'todos', 'todas', 'otro', 'otra',
        'otros', 'otras', 'no pudo', 'requiere', 'registrado', 'sobrescribiendo',
        'destruir', 'activar', 'fallo', 'hacia', 'encontrado', 'opcional', 'como',
        'escuchar',
    ];
}

/**
 * Elimina comentarios de JS conservando comillas (mismo estado que el medidor
 * de la medicion): dentro de strings no se interpretan // ni /*.
 */
function stripJsComments(string $src): string
{
    $out = '';
    $n = strlen($src);
    $i = 0;
    $state = 'code';
    while ($i < $n) {
        $c = $src[$i];
        $c2 = substr($src, $i, 2);
        if ($state === 'code') {
            if ($c2 === '//') { $state = 'line'; $i += 2; continue; }
            if ($c2 === '/*') { $state = 'block'; $i += 2; continue; }
            if ($c === "'") { $state = 's1'; $out .= $c; $i++; continue; }
            if ($c === '"') { $state = 's2'; $out .= $c; $i++; continue; }
            if ($c === '`') { $state = 'tpl'; $out .= $c; $i++; continue; }
            $out .= $c;
            $i++;
            continue;
        }
        if ($state === 'line') {
            if ($c === "\n") { $state = 'code'; $out .= $c; }
            $i++;
            continue;
        }
        if ($state === 'block') {
            if ($c2 === '*/') { $state = 'code'; $i += 2; continue; }
            if ($c === "\n") { $out .= $c; }
            $i++;
            continue;
        }
        // s1, s2 o tpl: hasta la comilla de cierre, respetando escapes
        $q = $state === 's1' ? "'" : ($state === 's2' ? '"' : '`');
        $out .= $c;
        if ($c === '\\' && $i + 1 < $n) {
            $out .= $src[$i + 1];
            $i += 2;
            continue;
        }
        if ($c === $q) { $state = 'code'; }
        $i++;
    }
    return $out;
}

/**
 * Normaliza un literal para decidir si contiene texto espanol visible:
 * quita interpolaciones ${...}, valores entre comillas (atributos), selectores
 * [..], etiquetas HTML y tokens con conectores (class, data-*, snake_case).
 */
function normalizeLiteral(string $lit): string
{
    $lit = preg_replace('/\$\{[^}]*\}/u', ' ', $lit) ?? $lit;
    $lit = preg_replace('/\$\{[^}]*$/u', ' ', $lit) ?? $lit;
    $lit = preg_replace('/"[^"]*"/u', ' ', $lit) ?? $lit;
    $lit = preg_replace('/\'[^\']*\'/u', ' ', $lit) ?? $lit;
    $lit = preg_replace('/\[[^\]]*\]/u', ' ', $lit) ?? $lit;
    $lit = preg_replace('/<[^>]*>/u', ' ', $lit) ?? $lit;
    $lit = preg_replace('/[A-Za-z0-9_$]*[_.\-][A-Za-z0-9_$\-]*/u', ' ', $lit) ?? $lit;
    $lit = preg_replace('/\s+/u', ' ', $lit) ?? $lit;
    return trim($lit);
}

/**
 * Clasifica un literal: null = exento/no texto de UI; 'lit' = texto de UI en
 * espanol; 'console' = diagnostico de consola en espanol (debe ir en ingles).
 */
function classifyLiteral(string $lit, string $before, array $words): ?string
{
    if (strlen($lit) < 3) { return null; }
    if (preg_match('/^(https?:|\/|#)/', $lit)) { return null; }
    if (preg_match('/^[A-Z][A-Z0-9_]*$/', $lit)) { return null; }   // enums del backend
    if (preg_match('/^[a-z][a-z0-9_]*$/', $lit)) { return null; }    // identificadores/campos
    if (preg_match('/^[a-z][a-z0-9_]*(\/[a-z0-9_-]+)+$/', $lit)) { return null; } // ids de modulo
    if (preg_match('/\/\.[a-z]+\(/', $lit)) { return null; }         // regex contra mensajes del backend
    if (preg_match('/\[[^\]]*=[^\]]*\]/', $lit) || preg_match('/\[[a-z-]+/', $lit)) { return null; } // selectores
    // indexOf/match/includes(...): se compara con el mensaje EN ESPAÑOL del backend
    if (preg_match('/(indexOf|includes|match|search|split|startsWith|endsWith|exec|test)\s*\(\s*$/', substr($before, -70))) {
        return null;
    }

    $cand = normalizeLiteral($lit);
    if ($cand === '') { return null; }
    if (!preg_match('/[a-z]/', $cand)) { return null; }              // solo mayusculos/codigos

    $accent = preg_match('/[áéíóúñü¿¡]/u', $cand) === 1;
    $wordRe = '/(?:^|[^a-z])(?:' . implode('|', array_map(static function (string $w): string {
        return preg_replace('/\s+/', '\s+', preg_quote($w, '/'));
    }, $words)) . ')(?:[^a-z]|$)/ui';
    $hasWord = preg_match($wordRe, $cand) === 1;
    if (!$accent && !$hasWord) { return null; }

    $isConsole = preg_match('/console\.\w+\s*\([^;]*$/', $before) === 1;
    return $isConsole ? 'console' : 'lit';
}

/**
 * Recorre public/js (sin core/i18n.js: diccionario embebido) buscando
 * literales/console en espanol fuera de t().
 *
 * @return list<array{where: string, kind: string, text: string}>
 */
function collectSpanishLiterals(string $root): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public/js'));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
            $path = $file->getPathname();
            if (str_ends_with(str_replace('\\', '/', $path), 'public/js/core/i18n.js')) {
                continue; // diccionario embebido (BUILTIN): exento
            }
            $files[] = $path;
        }
    }
    sort($files);

    $words = i18nSpanishWords();
    $hits = [];

    foreach ($files as $path) {
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $stripped = stripJsComments((string) file_get_contents($path));
        $pattern = '/\'([^\'\\\\\n]*)\'|"([^"\\\\\n]*)"|`([^`]*)`/';
        if (!preg_match_all($pattern, $stripped, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m[0] as $idx => $full) {
            $lit = $full[1] !== -1
                ? ($m[1][$idx][1] !== -1 ? $m[1][$idx][0] : ($m[2][$idx][1] !== -1 ? $m[2][$idx][0] : $m[3][$idx][0]))
                : '';
            if ($lit === '') { continue; }
            $off = $full[1];
            $before = substr($stripped, max(0, $off - 200), min(200, $off));
            // llamada t(...) como contenedor: ya esta traducido
            if (preg_match('/(?<![A-Za-z0-9_$])t\s*\(\s*$/', substr($before, -30))) {
                continue;
            }
            $kind = classifyLiteral($lit, $before, $words);
            if ($kind === null) { continue; }
            $line = substr_count(substr($stripped, 0, $off), "\n") + 1;
            $text = strlen($lit) > 120 ? substr($lit, 0, 120) . '...' : $lit;
            $hits[] = ['where' => $rel . ':' . $line, 'kind' => $kind, 'text' => $text];
        }
    }

    return $hits;
}

// ---------------------------------------------------------------------------
// 1) Carga de diccionarios
// ---------------------------------------------------------------------------

$errors = 0;
$dicts = [];

foreach ($locales as $loc) {
    $file = $localesDir . '/' . $loc . '.json';
    if (!is_file($file)) {
        fail("Falta el diccionario resources/locales/$loc.json");
        $errors++;
        continue;
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        fail("JSON invalido en resources/locales/$loc.json: " . json_last_error_msg());
        $errors++;
        continue;
    }
    $flat = [];
    flatten($data, '', $flat);
    $dicts[$loc] = $flat;
}

if (!isset($dicts['es'])) {
    fail('Sin es.json no hay fuente que comparar');
    exit(1);
}

$esKeys = $dicts['es'];
$esCount = count($esKeys);
echo "es.json: $esCount claves\n";

// ---------------------------------------------------------------------------
// 2) Paridad entre idiomas
// ---------------------------------------------------------------------------

foreach ($locales as $loc) {
    if ($loc === 'es' || !isset($dicts[$loc])) {
        continue;
    }
    $other = $dicts[$loc];
    $missing = array_diff_key($esKeys, $other);
    $extra = array_diff_key($other, $esKeys);

    foreach (array_keys($missing) as $k) {
        fail("$loc.json no tiene la clave '$k'");
        $errors++;
    }
    foreach (array_keys($extra) as $k) {
        fail("$loc.json tiene la clave '$k' que no existe en es.json");
        $errors++;
    }

    // Placeholders {param} idénticos a la fuente
    foreach ($esKeys as $k => $v) {
        if (!array_key_exists($k, $other)) {
            continue;
        }
        $a = extractPlaceholders($v);
        $b = extractPlaceholders($other[$k]);
        if ($a !== $b) {
            fail("$loc.json clave '$k': placeholders {..} no coinciden con es.json [" . implode(',', $a) . "] vs [" . implode(',', $b) . "]");
            $errors++;
        }
    }

    echo ($errors === 0 ? '' : '') . "$loc.json: " . count($other) . " claves comparadas con es.json\n";
}

// ---------------------------------------------------------------------------
// 3) Claves usadas en el codigo presentes en es.json
// ---------------------------------------------------------------------------

$used = collectUsedKeys($root);
echo 'Claves usadas en el codigo: ' . count($used) . "\n";

foreach ($used as $info) {
    $k = $info['key'];
    if (!array_key_exists($k, $esKeys)) {
        fail("Clave usada '$k' ($info[where]) no existe en es.json");
        $errors++;
    }
}

// ---------------------------------------------------------------------------
// 4) Aviso: claves de es.json no referenciadas (no fatal)
// ---------------------------------------------------------------------------

$unused = array_diff_key($esKeys, $used);
foreach (array_keys($unused) as $k) {
    echo "AVISO: clave sin uso en el codigo: '$k'\n";
}

// ---------------------------------------------------------------------------
// 5) Literales/console en espanol fuera de t() en public/js (sprint v1.10.3)
// ---------------------------------------------------------------------------

$spanish = collectSpanishLiterals($root);
echo 'Literales/console en espanol fuera de t() (public/js): ' . count($spanish) . "\n";
foreach ($spanish as $hit) {
    $label = $hit['kind'] === 'console' ? 'Consola en espanol' : 'Literal en espanol';
    fail("$label fuera de t(): \"" . $hit['text'] . "\" (" . $hit['where'] . ')');
    $errors++;
}

// ---------------------------------------------------------------------------
// Resultado
// ---------------------------------------------------------------------------

if ($errors > 0) {
    echo "RESULTADO: $errors error(es)\n";
    exit(1);
}

echo "RESULTADO: OK (0 errores, " . count($unused) . " aviso(s))\n";
exit(0);
