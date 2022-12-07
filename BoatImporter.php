<?php

use App\Helpers;
use App\SlotManagement;
use App\WcProduct;
use Themosis\Support\Facades\Action;
use Themosis\Support\Facades\Filter;

Action::add('acf/save_post', ['BoatImport','saveAcfOptions'], 20);
Filter::add('acf/load_value/name=columns', ['BoatImport','setColumns'], 10, 3);
Filter::add('acf/load_field/name=columns', ['BoatImport','setColumnValues'], 10, 3);
Filter::add('acf/render_field/key=field_6157d9b323222', ['BoatImport','echoShopResults'], 10, 3);

class BoatImport
{

    function __construct($file_id = false)
    {
        if (!$file_id) {
            $file_id        = intval(get_field('field_619ce93cccd00', 'option'));
        }

        $this->file_id      = $file_id;
        $this->cache_key    = [
            get_post_type($file_id),
            $file_id,
        ];

        $this->file_data        = $this->set_file_data($file_id) ?: [];
        // $this->cache_keys[] = 'set_file_data'; // We do not clear cache this line.
        $this->column           = intval(get_field('field_6099289594200', 'option'));
        $this->file_columns     = $this->set_file_columns() ?: [];
        $this->option_columns   = Helpers::get_transient('boat_import_columns') ?: [];
        $this->cache_keys[]     = 'boat_import_columns';
        $this->paired_data      = $this->set_paired_data() ?: [];
        $this->cache_keys[]     = 'set_paired_data';
        $this->datatable_columns = $this->set_datatable_columns() ?: [];
    }

    function delete_transients() {
        $cache_keys = $this->cache_keys ?: [];
        $cache_key  = $this->cache_key;
        foreach ($cache_keys as $cache) {
            $new_key    = $cache_key;
            $new_key[]  = $cache;
            Helpers::delete_transient(implode('_', $new_key));
        }

        return true;
    }

    function get_file_id() {
        return $this->file_id;
    }

    function get_columns($keys = false, $labels = false) {

        if (!$keys) {
            return $this->file_columns;
        }

        // Temp.
        return $this->file_columns;

        $return = [];
        foreach ($this->file_columns as $column){
            $return[]   = strtolower(Helpers::cache_key_format($column, false));
        }

        return  $return;
    }

    function get_paired_data($data_row) {
        if (!is_array($this->option_columns)){
            return [];
        }

        $keys   = array_filter(array_column($this->option_columns, 'field_60220c31b9c22'));
        $new    = [];
        foreach ($keys as $key => $field) {
            if ($field == 'order_netvisor_invoicenumber') {
                $data_row[$key] = SELF::get_order_column($data_row[$key]);
            }

            if ($field == 'boat_id') {
                $hin            = str_replace(' ', '', $data_row[$key]);
                $data_row[$key] = trim(strtoupper($hin));
            }

            // if order field has been selected twice.
            if (key_exists($field, $new)) {
                $field = $field . '_2';
            }

            $new[$field] = $data_row[$key];
        }

        return $new;
    }

    /**
     * Load data table columns.
     */
    function set_datatable_columns() {
        $data = $this->paired_data;
        if (is_array($data) && empty($data)) {
            return [];
        }

        $process_data   = $data;
        $columns        = reset($process_data);
        $keys           = array_flip(array_keys($columns));

        $args = [
            'orderable' => true,
            'width'     => 'auto',
        ];

        $return = App\Helpers::getTableFields($keys, $args);
        return App\SlotQueries::safe_json_encode($return);
    }

     /**
     * Set processesed data.
     */
    function set_paired_data() {

        $data           = $this->file_data;
        $file_id        = $this->file_id;
        if (!$file_id) {
            return [];
        }

        $cache_key      = $this->cache_key;
        $cache_key[]    = 'set_paired_data';
        $cache_key      = implode('_', $cache_key);
        $cache          = Helpers::get_transient($cache_key);
        if ($cache) {
            return $cache;
        }

        $new = [];
        foreach ($data as $key => $data_row) {
            $row    = $this->get_paired_data($data_row);
            if (array_sum(array_values($row)) == 0 ){
                continue;
            }

            // Required field
            $hin    = $row['boat_id'];
            $isHin  = Boat::getHINfromString($hin);
            if (!$isHin) {
                continue;
            }

            $row['boat']    = '';
            $boats          = Boat::getBoatbyHIN($row['boat_id']);
            if (is_array($boats)) {
                $row['boat'] = implode(',', $boats);
            }

            // Set defaults.
            $row['shop_order']          = '';
            $row['shop_order_import']   = '';
            $row['shop_order_form']     = '';
            if (!empty($row['order_netvisor_invoicenumber'])) {
                $orders          = OrderTable::getOrderIdsByNetvisorKeys(0, $row['order_netvisor_invoicenumber']);
                if (is_array($orders) && !empty($orders)) {
                    $row['shop_order'] = implode(',', $orders);
                    $row['shop_order_import'] = OrderTable::getViewOrder($row['shop_order']);

                    if(empty($row['boat'])) {
                        $row['shop_order_import'] = OrderTable::getViewOrder($row['shop_order'], 'Add missing boat', '?show_modal=order_new_boat');
                    }

                    if (get_post_type($row['shop_order']) == 'shop_order') {
                        $cache = $row;
                        unset($cache['shop_order']);
                        // unset($cache['shop_order_form']);
                        unset($cache['boat']);
                        $row['shop_order_form'] = view('forms.order_boat_csv_form_import', [
                           'title'           => 'Import CSV to order',
                           'order_id'        => $row['shop_order'],
                           'data'            => $cache,
                           'tooltip_title'   => 'Update order data',
                           'tooltip'         => true,
                        ])->render();
                    }
                }
            }

            if (empty($row['shop_order'])) {
                $slug = [
                    'invoicenumber' => $row['order_netvisor_invoicenumber'],
                ];

                $result = Netvisor::getOrdersList(null, $slug, false, true);
                if (is_array($result) && isset($result['NetvisorKey']) && isset($result['InvoiceNumber'])) {
                    $row['shop_order_import']   =  (new NetvisorQuery())->buildOrderForm($result['NetvisorKey'], $result['InvoiceNumber'],false, 'Import order?', app('form'), app('field'))->render();
                }
            }

            $row['csv_row'] = $key;
            $new[] = $row;
        }

        Helpers::set_transient($cache_key, $new, 60*60);
        return $new;
    }

    /**
     * Catch acf form save
     *
     * @param integer $post_id
     */
    static public function saveAcfOptions( $post_id ){
        Helpers::WriteLog('BoatImport@saveAcfOptions');

        if ( $post_id == 'options' && isset($_POST['acf']['field_619ce93cccd00']) && !empty($_POST['acf']['field_619ce93cccd00']) ) {
            $fields     = $_POST['acf'];
            $columns    = (isset($fields['field_5fea2b813b99f']) && !empty($fields['field_5fea2b813b99f'])) ? $fields['field_5fea2b813b99f'] : [];
            $file_id    = (isset($fields['field_619ce93cccd00']) && !empty($fields['field_619ce93cccd00'])) ? intval($fields['field_619ce93cccd00']) : 0;
            $delete_cache = (isset($fields['field_6157d9b323222']) && !empty($fields['field_6157d9b323222'])) ? intval($fields['field_6157d9b323222']) : 0;
            Helpers::delete_transient('boat_import_columns');
            Helpers::set_transient('boat_import_columns', $columns, -1);
            if ($delete_cache) {
                Helpers::WriteLog('Delete cache');
                $data = New BoatImport($file_id);
                $data->delete_transients();
            }
        }
    }

     /**
     * Prefill values.
     */
    public static function setColumnValues( $field ) {

        foreach($field['sub_fields'] as $key => $sub_field) {

            $file_id    = get_field('field_619ce93cccd00', 'option');
            $file       = new BoatImport($file_id);
            $order_table = new OrderTable($file_id);
            // $boat_table = new Boat($file_id);

            switch ($sub_field['name']){
                case 'csv_column':
                    $field['sub_fields'][$key]['choices'] = array_combine($file->get_columns(), $file->get_columns());
                break;
                case 'order_field':
                    $field['sub_fields'][$key]['choices'] = array_combine(array_column($order_table->acf_fields, 'name'), array_column($order_table->acf_fields, 'label'));
                break;
                case 'boat_field':
                  //   $field['sub_fields'][$key]['choices'] = array_combine(array_column($boat_table->acf_fields, 'name'), array_column($boat_table->acf_fields, 'label'));
                break;
            }
        }

        return $field;
    }

    /**
     * Prefill option column values.
     * filter: 'acf/load_field/name=columns'
     *
     * @param $value
     * @param $post_id
     * @param $field
     */
    public static function setColumns($value, $post_id, $field) {
        $file       = new BoatImport();
        $columns    = $file->get_columns(true);

        if (!empty($columns)) {
            foreach($columns as $key => $column) {
                $value[$key]['field_5ff1a544ede24'] = $column;
            }
        }

        return $value;
    }

    /**
     * @param integer $file_id
     * @param boolean $column get first row.
     */
    function set_file_columns() {
        $column     = $this->column;
        $file_data  = $this->file_data;
        if (key_exists($column, $file_data)) {
            return $file_data[$column];
        }

        return [];
    }

    function set_file_data($file_id, $column = false) {
        $file_id = $this->file_id;

        if (!$file_id) {
            return [];
        }

        $cache_key = $this->cache_key;
        $cache_key[]    = 'set_file_data';
        $cache_key      = implode('_', $cache_key);
        $cache          = Helpers::get_transient($cache_key);

        if ($cache) {
            return $cache;
        }

        $file       = get_attached_file($file_id);
        $cache      = [];
        if (file_exists($file) && ($handle = fopen($file, 'r')) !== FALSE) { // Check the resource is valid
            $i = 0;
            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) { // Check opening the file is OK!
                $cache[] = $data;
                $i++;
            }
            fclose($handle);
        }

        // Return colum
        if (is_int($column)){
            $cache = $cache[$column];
        }

        Helpers::set_transient($cache_key, $cache, -1);
        return $cache;
    }

    function get_netvisor_ids() {
        return $this->netvisor_ids;
    }

    private static function set_netvisor_ids($columns, $file_id) {
        $order_fields   = ($columns) ? array_column($columns, 'field_60220c31b9c22') : [];
        $cache_expire   = 60*60*3;
        $id             = 'order_netvisor_invoicenumber';
        $cache_key      = implode('_', [
            $id,
            $file_id,
        ]);

        $cache = Helpers::get_transient( $cache_key );
        if ($cache) {
            return $cache;
        }

        if (!empty($order_fields)) {
            $i              = array_search($id, $order_fields);
            $data           = new BoatImport();
            $data_column    = array_column($data->file_data, $i);
            $cache          = [];
            foreach($data_column as $key => $order_id) {
                if ( empty($order_id) ) {
                    continue;
                }

                preg_match_all('/[0-9]{4}/', $order_id, $output_array);
                if (!empty($output_array[0])) {
                    $cache[$key] = implode(',', $output_array[0]);
                }
            }

            Helpers::set_transient( $cache_key, $cache, $cache_expire );
            return $cache;
        }

        return [];
    }

    public static function get_order_column($order_row) {
        if (empty($order_row)) {
            return '';
        }

        preg_match_all('/[0-9]{4}/', $order_row, $output_array);
        if (!empty($output_array[0])) {
            return implode(',', $output_array[0]);
        }

        return '';
    }


    public static function echoShopResults($field) {
       //  Helpers::clearDebug();

        $data           = new BoatImport();
        echo ' File has ' . count($data->file_data) . ' lines & ' . count($data->file_columns) . ' columns.';
        echo '<br>';

        if (!empty($data->paired_data) && isset($data->paired_data[0])) {
            echo ' Found ' . (count($data->paired_data)) . ' orders & selected '. count($data->paired_data[0]) . ' columns.';
            echo '<br>';
        }
        /*
        echo ' Found orders without boats ' . (count($data->paired_data));
        echo '<br>';
        echo ' Found boats without orders ' . (count($data->paired_data));
        echo '<br>';
        */

        return $field;
    }


    public static function getPairedData($order_id) {
        $our_reference    = trim(get_field('our_reference', $order_id));
        $our_reference    = str_replace(' ', '', $our_reference);
        $our_reference    = str_replace('SX', '', $our_reference);
        $cache_key     = implode('_', [
           'BoatImport',
           $our_reference
        ]);

        $cache = App\Helpers::get_transient($cache_key);

        if ($cache) {
           return $cache;
        }

        if ($cache === false) {
           $dataimport    = new BoatImport();
           $found         = array_search($our_reference, array_column($dataimport->paired_data, 'our_reference'));
           if ($found) {
              $cache = $dataimport->paired_data[$found];
              App\Helpers::set_transient($cache_key, $cache, 60*60);

              return $cache;
           }
        }

        return false;
     }


}
