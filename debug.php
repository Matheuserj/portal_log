<?php
header('Content-Type: text/plain; charset=utf-8');

$app = $_GET['app'] ?? '';
echo "==================================================\n";
echo "DOCKER CONTAINER PATH DEBUGGER (WITH TREE SIMULATION)\n";
echo "==================================================\n\n";

if (empty($app)) {
    echo "Erro: Forneça o parâmetro ?app=<nome_do_app> na URL.\n";
    echo "Exemplo: debug.php?app=db_app_teste_uat\n\n";
    echo "Diretórios disponíveis em /app/:\n";
    if (is_dir('/app')) {
        print_r(scandir('/app'));
    } else {
        echo "/app não é um diretório ou não existe.\n";
    }
    exit;
}

// Copy get_file_tree helper to debug context
function get_file_tree($dir, $base_dir) {
    $result = [];
    if (!is_dir($dir)) {
        return $result;
    }
    
    $items = @scandir($dir);
    if ($items === false) {
        return $result;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        // Exclude directories to avoid heavy listing
        if (in_array(strtolower($item), ['vendor', 'node_modules', '.git', '.github', 'dist', 'build', '.cache'])) {
            continue;
        }

        $path = $dir . '/' . $item;
        $normalized_base = str_replace('\\', '/', $base_dir);
        $normalized_path = str_replace('\\', '/', $path);
        $rel_path = ltrim(str_replace($normalized_base, '', $normalized_path), '/');

        if (is_dir($path)) {
            $result[] = [
                'name' => $item,
                'path' => $rel_path,
                'type' => 'directory',
                'children' => get_file_tree($path, $base_dir)
            ];
        } else {
            $result[] = [
                'name' => $item,
                'path' => $rel_path,
                'type' => 'file',
                'size' => @filesize($path) ?: 0
            ];
        }
    }

    usort($result, function($a, $b) {
        if ($a['type'] === $b['type']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return ($a['type'] === 'directory') ? -1 : 1;
    });

    return $result;
}

$app_dir = '/app/' . $app;
echo "1. APP DIRECTORY CHECK:\n";
echo "   Path: $app_dir\n";
echo "   Exists: " . (file_exists($app_dir) ? 'SIM' : 'NÃO') . "\n";
echo "   Is Dir: " . (is_dir($app_dir) ? 'SIM' : 'NÃO') . "\n";

$src_dir = $app_dir . '/src';
echo "\n2. SRC DIRECTORY CHECK:\n";
echo "   Path: $src_dir\n";
echo "   Exists: " . (file_exists($src_dir) ? 'SIM' : 'NÃO') . "\n";
echo "   Is Dir: " . (is_dir($src_dir) ? 'SIM' : 'NÃO') . "\n";

if (file_exists($src_dir)) {
    echo "\n3. SIMULATING FILE TREE GENERATION:\n";
    $start = microtime(true);
    $tree = get_file_tree($src_dir, $src_dir);
    $end = microtime(true);
    
    echo "   Success: SIM\n";
    echo "   Execution Time: " . round($end - $start, 4) . " seconds\n";
    echo "   Total Root Nodes: " . count($tree) . "\n";
    
    // Count total files and folders recursively
    $total_files = 0;
    $total_folders = 0;
    $count_nodes = function($nodes) use (&$count_nodes, &$total_files, &$total_folders) {
        foreach ($nodes as $n) {
            if ($n['type'] === 'directory') {
                $total_folders++;
                $count_nodes($n['children']);
            } else {
                $total_files++;
            }
        }
    };
    $count_nodes($tree);
    
    echo "   Total Files found: $total_files\n";
    echo "   Total Directories found: $total_folders\n";
    echo "   JSON Size: " . strlen(json_encode($tree)) . " bytes\n";
    
    echo "\n4. SAMPLE ROOT NODES PREVIEW:\n";
    print_r(array_slice($tree, 0, 15));
}
