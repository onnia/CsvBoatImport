# CsvBoatImport
Helper class to load csv data as [Datatables.js](https://datatables.net/) friendly format. 

### Usage

```

<?php

$importer = New BoatImport($file_id);

# output the csv as array with all lines
var_dump($importer->file_data);

# output csv file content as array selected 'importer->option_columns'  columns.
var_dump($importer->get_paired_data());

# output CSV file content for WooCommerce order if $order_id exists in the file data.
$lineData = BoatImport::getPairedData($order_id);

# output the CSV file as Datatable.js friendly format.
$dataTableColumns = $importer->datatable_columns();
$dataTableData = $importer->paired_data();

?>
<script> 
  $('#myTable').DataTable( {
    data: {!! $dataTableData !!},
    columns: {!! $dataTableColumns !!},
} );
</script>

<table id="myTable" >
...
</table>
```
