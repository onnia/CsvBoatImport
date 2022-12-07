# CsvBoatImport
Helper class to load csv data

### Usage

```
$importer = New BoatImport($file_id);

# output the csv as array
var_dump($importer->get_paired_data());

$importer = New BoatImport($file_id);

# output All csv file content as array.
var_dump($importer->get_paired_data());

# output CSV file content for WooCommerce order if order exists in the data.
$lineData = BoatImport::getPairedData($order_id);


```
