<?php

namespace App\Helpers;

trait CsvHelper
{
    /**
     * Transform csv file to Array with header as key
     * @param $file
     * @param bool $withHeader
     * @return array
     */
    public function csvToArray($file, bool $withHeader = true): array
    {
        $array = [];
        $header = [];

        // Use fgetcsv to properly handle multi-line CSV fields
        if (($handle = fopen($file, 'r')) !== false) {
            $rowIndex = 0;
            while (($data = fgetcsv($handle)) !== false) {
                if ($withHeader && $rowIndex === 0) {
                    // Process header row
                    foreach ($data as $col) {
                        array_push($header, strtolower(str_replace(' ', '_', $col)));
                    }
                } else {
                    // Process data rows
                    if ($withHeader) {
                        // Ensure row has same number of columns as header
                        $rowData = array_pad($data, count($header), '');
                        $rowData = array_slice($rowData, 0, count($header));
                        $array[] = array_combine($header, $rowData);
                    } else {
                        $array[] = $data;
                    }
                }
                $rowIndex++;
            }
            fclose($handle);
        }

        return $array;
    }
}