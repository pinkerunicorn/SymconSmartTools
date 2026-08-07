<?php
$json = file_get_contents('C:\Users\grass\Documents\Symcon\SymconSmartTools\DeviceRegistry\form.json');
$form = json_decode($json, true);

$changed = false;
foreach ($form['elements'] as &$el) {
    if ($el['type'] === 'ExpansionPanel' && isset($el['items'])) {
        $newItems = [];
        foreach ($el['items'] as $item) {
            if ($item['type'] === 'Button' && strpos($item['onClick'], 'DiscoverDevices') !== false) {
                $changed = true;
                continue;
            }
            if ($item['type'] === 'RowLayout') {
                // Keep existing row layout if we already modified it
                $newItems[] = $item;
                continue;
            }
            if ($item['type'] === 'List' && str_starts_with($item['name'], 'Devices')) {
                $changed = true;
                $newItems[] = [
                    'type' => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Button',
                            'caption' => 'Suchen (' . $item['caption'] . ')',
                            'onClick' => "SDR_DiscoverDevices(\$id, '" . $item['name'] . "');"
                        ],
                        [
                            'type' => 'Button',
                            'caption' => 'Alle löschen',
                            'onClick' => "SDR_ClearList(\$id, '" . $item['name'] . "');",
                            'confirm' => "Möchtest du wirklich alle Einträge aus der Liste unwiderruflich löschen?"
                        ]
                    ]
                ];
            }
            $newItems[] = $item;
        }
        $el['items'] = $newItems;
    }
}
if ($changed) {
    file_put_contents('C:\Users\grass\Documents\Symcon\SymconSmartTools\DeviceRegistry\form.json', json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "Form updated successfully.\n";
} else {
    echo "No changes needed.\n";
}
