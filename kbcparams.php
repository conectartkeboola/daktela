<?php
// parametry importované z konfiguračního JSON řetězce v definici PHP aplikace v KBC

$configFile = $dataDir."config.json";
$config     = json_decode(file_get_contents($configFile), true);

// parametry importované z konfiguračního JSON v KBC
$confParam = $config["parameters"];
$processedInstances     = $confParam["processedInstances"]; // pole s údaji, které instance mají být zpracovány - např. ["1" => true, "2" => true, ...]
$incrementalMode        = $confParam["incrementalMode"];    // pole s údaji o inkrementálním režimu zpracování
$jsonParse              = $confParam["jsonParse"];          // pole s parametry parsování JSON řetězců
$diagOutOptions         = $confParam["diagOutOptions"];     // diag. výstup do logu Jobs v KBC - klíče: basicStatusInfo, jsonParseInfo // ZRUŠIT ... , basicIntegrInfo, detailIntegrInfo
$adhocDump              = $confParam["adhocDump"];          // diag. výstup do logu Jobs v KBC - klíče: active, idFieldSrcRec

// parametry inkrementálního režimu
$incrementalOn     = empty($incrementalMode['incrementalOn']) ? false : true;   // vstupní hodnota false se vyhodnotí jako empty :)
$incrCallsOnly     = empty($incrementalMode['incrCallsOnly']) ? false : true;   // vstupní hodnota false se vyhodnotí jako empty :)
$histDays          = $incrementalMode['histDays'];          // datum. rozsah historie pro tvorbu reportu - pole má klíče "start" a "end", kde musí být "start" >= "end"

/* import parametru z JSON řetězce v definici Customer Science PHP v KBC:
    {
      "incrementalMode": {
        "incrementalOn": true,
        "incrCallsOnly": true,
        "histDays": {
          "start": 3,
          "end":   0
        }
      },
      "processedInstances": {
        "1": true,
        "2": true,
        "3": true,
        "4": true
      },
      "jsonParse": {
        "activities.item_parseNestedAttrs": false 
      },
      "diagOutOptions": {
        "basicStatusInfo": true,
        "jsonParseInfo": false
      },
      "adhocDump": {
        "active": false,
        "idFieldSrcRec": "301121251"
      }
   }
  -> podrobnosti viz https://developers.keboola.com/extend/custom-science
*/