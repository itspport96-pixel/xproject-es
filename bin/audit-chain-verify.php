<?php
/**
 * M2: Verificación de integridad de la cadena de hashes de auditoría
 * 
 * Recorre todas las filas de audit_logs ordenadas por seq y verifica que:
 * hash_integridad = SHA256(prev_hash || payload_canónico)
 * 
 * Uso: php bin/audit-chain-verify.php [--tenant-id=XXX]
 * 
 * Salida:
 * - 0: Cadena íntegra (0 rupturas)
 * - 1: Cadena rota (hay rupturas)
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use App\Shared\Container\Container;
use App\Shared\Services\DatabaseService;

// Parsear argumentos
$options = getopt('', ['tenant-id:']);
$tenantFilter = $options['tenant-id'] ?? null;

echo "=== Verificación de Integridad de Cadena de Auditoría ===\n\n";

try {
    $container = Container::getInstance();
    /** @var DatabaseService $db */
    $db = $container->get('database');
    $pdo = $db->getConnection();
    
    // Preparar query
    $sql = 'SELECT id, seq, tenant_id, hash_integridad, 
                   timestamp, operacion, modulo, recurso, tipo_cambio,
                   valores_anteriores, valores_nuevos, resultado, razon_fallo,
                   ip_address, user_agent, dispositivo_id, duracion_operacion,
                   detalles_adicionales, entidad_id, request_id
            FROM audit_logs';
    
    $params = [];
    if ($tenantFilter !== null) {
        $sql .= ' WHERE tenant_id = ?';
        $params[] = $tenantFilter;
        echo "Filtrando por tenant: {$tenantFilter}\n";
    }
    
    $sql .= ' ORDER BY tenant_id, seq';
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $totalRows = 0;
    $brokenChains = 0;
    $currentTenant = null;
    $prevHash = null;
    $prevSeq = null;
    
    echo "Verificando cadena...\n";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $totalRows++;
        
        // Si cambiamos de tenant, resetear prevHash
        if ($currentTenant !== $row['tenant_id']) {
            $currentTenant = $row['tenant_id'];
            $prevHash = null;
            $prevSeq = null;
            echo "\nTenant: {$currentTenant}\n";
        }
        
        // Reconstruir payload canónico (sin hash_integridad, sin seq)
        $payload = [
            'id' => $row['id'],
            'tenant_id' => $row['tenant_id'],
            'usuario_id' => $row['usuario_id'],
            'operacion' => $row['operacion'],
            'modulo' => $row['modulo'],
            'recurso' => $row['recurso'],
            'tipo_cambio' => $row['tipo_cambio'],
            'valores_anteriores' => $row['valores_anteriores'],
            'valores_nuevos' => $row['valores_nuevos'],
            'resultado' => $row['resultado'],
            'razon_fallo' => $row['razon_fallo'],
            'ip_address' => $row['ip_address'],
            'user_agent' => $row['user_agent'],
            'dispositivo_id' => $row['dispositivo_id'],
            'timestamp' => $row['timestamp'],
            'duracion_operacion' => $row['duracion_operacion'],
            'detalles_adicionales' => $row['detalles_adicionales'],
            'entidad_id' => $row['entidad_id'],
            'request_id' => $row['request_id'],
        ];
        
        // Ordenar claves recursivamente para canonicalización
        ksort_recursive($payload);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        // Calcular hash esperado
        $expectedHash = hash('sha256', ($prevHash ?? '') . $payloadJson);
        
        // Verificar
        if ($row['hash_integridad'] !== $expectedHash) {
            $brokenChains++;
            echo "  ❌ ROTURA en seq={$row['seq']} id={$row['id']}\n";
            echo "     Esperado: {$expectedHash}\n";
            echo "     Obtenido: {$row['hash_integridad']}\n";
            if ($prevSeq !== null) {
                echo "     Previo: seq={$prevSeq}\n";
            }
        }
        
        // Avanzar
        $prevHash = $row['hash_integridad'];
        $prevSeq = $row['seq'];
    }
    
    echo "\n=== Resultado ===\n";
    echo "Total de registros: {$totalRows}\n";
    echo "Cadenas rotas: {$brokenChains}\n";
    
    if ($brokenChains === 0) {
        echo "\n✅ Cadena íntegra\n";
        exit(0);
    } else {
        echo "\n❌ Cadena comprometida\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Ordenar array recursivamente por claves
 */
function ksort_recursive(array &$array): void
{
    ksort($array);
    foreach ($array as $key => $value) {
        if (is_array($value)) {
            ksort_recursive($array[$key]);
        }
    }
}
