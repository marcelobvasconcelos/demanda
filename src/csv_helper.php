<?php
/**
 * CSV Storage Helper - Substituto leve do MySQL para Render
 */

define('CSV_DATA_DIR', __DIR__ . '/data');

function csv_init(): void
{
    if (!is_dir(CSV_DATA_DIR)) {
        @mkdir(CSV_DATA_DIR, 0755, true);
    }
}

function csv_get_path(string $table): string
{
    csv_init();
    return CSV_DATA_DIR . '/' . $table . '.csv';
}

function csv_load(string $table): array
{
    $path = csv_get_path($table);
    if (!file_exists($path)) {
        return [];
    }
    $rows = [];
    $handle = fopen($path, 'r');
    if ($handle) {
        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            return [];
        }
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($headers, $row);
            if ($data !== false) {
                $rows[] = $data;
            }
        }
        fclose($handle);
    }
    return $rows;
}

function csv_save(string $table, array $rows): void
{
    $path = csv_get_path($table);
    $handle = fopen($path, 'w');
    if ($handle) {
        if (!empty($rows)) {
            fputcsv($handle, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
        }
        fclose($handle);
    }
}

function csv_find(string $table, array $conditions): ?array
{
    $rows = csv_load($table);
    foreach ($rows as $row) {
        $match = true;
        foreach ($conditions as $key => $value) {
            if (($row[$key] ?? null) != $value) {
                $match = false;
                break;
            }
        }
        if ($match) {
            return $row;
        }
    }
    return null;
}

function csv_find_all(string $table, array $conditions = []): array
{
    $rows = csv_load($table);
    if (empty($conditions)) {
        return $rows;
    }
    return array_filter($rows, function ($row) use ($conditions) {
        foreach ($conditions as $key => $value) {
            if (($row[$key] ?? null) != $value) {
                return false;
            }
        }
        return true;
    });
}

function csv_insert(string $table, array $data): int
{
    $rows = csv_load($table);
    $rows[] = $data;
    csv_save($table, $rows);
    return count($rows);
}

function csv_update(string $table, array $conditions, array $data): int
{
    $rows = csv_load($table);
    $count = 0;
    foreach ($rows as &$row) {
        $match = true;
        foreach ($conditions as $key => $value) {
            if (($row[$key] ?? null) != $value) {
                $match = false;
                break;
            }
        }
        if ($match) {
            $row = array_merge($row, $data);
            $count++;
        }
    }
    if ($count > 0) {
        csv_save($table, $rows);
    }
    return $count;
}

function csv_upsert(string $table, array $conditions, array $data): int
{
    $existing = csv_find($table, $conditions);
    if ($existing) {
        return csv_update($table, $conditions, $data);
    }
    return csv_insert($table, array_merge($conditions, $data));
}

function csv_count(string $table, array $conditions = []): int
{
    return count(csv_find_all($table, $conditions));
}

function csv_delete(string $table, array $conditions): int
{
    $rows = csv_load($table);
    $original = count($rows);
    $rows = array_filter($rows, function ($row) use ($conditions) {
        foreach ($conditions as $key => $value) {
            if (($row[$key] ?? null) != $value) {
                return true;
            }
        }
        return false;
    });
    csv_save($table, array_values($rows));
    return $original - count($rows);
}

// Funções específicas para o Demanda

function csv_get_lojas(): array
{
    return csv_find_all('lojas');
}

function csv_get_lotes(?string $mesAnoRef = null): array
{
    if ($mesAnoRef) {
        return csv_find_all('lotes', ['mes_ano_referencia' => $mesAnoRef]);
    }
    return csv_find_all('lotes');
}

function csv_espelhar_lotes(array $lotes, string $mesAnoRef): void
{
    foreach ($lotes as $r) {
        csv_upsert('lotes', ['id' => $r['id']], [
            'mes_ano_referencia' => $mesAnoRef,
            'usuario_uid' => $r['usuario_uid'] ?? $r['usuario_id'] ?? '',
            'usuario_email' => $r['usuario_email'] ?? '',
            'peca_servico' => $r['peca_servico'] ?? '',
            'quantidade' => intval($r['quantidade'] ?? $r['qtd'] ?? 0),
            'qtd_entregue' => intval($r['qtd_entregue'] ?? $r['entregue'] ?? 0),
            'preco_unitario' => floatval($r['preco_unitario'] ?? $r['precoU'] ?? 0),
            'tamanho' => $r['tamanho'] ?? '-',
            'valor_recebido' => floatval($r['valor_recebido'] ?? 0),
            'data_cadastro' => $r['data_cadastro'] ?? null,
            'data_entrega' => $r['data_entrega'] ?? $r['data_ultima_entrega'] ?? null,
            'sincronizado' => 1,
            'atualizado_em' => date('Y-m-d H:i:s'),
        ]);
    }
}

function csv_count_pendentes(): int
{
    return csv_count('lotes', ['sincronizado' => '0']);
}

function csv_get_last_updated(): ?string
{
    $lotes = csv_find_all('lotes');
    $max = null;
    foreach ($lotes as $l) {
        $ts = $l['atualizado_em'] ?? '';
        if ($ts && ($max === null || $ts > $max)) {
            $max = $ts;
        }
    }
    return $max;
}