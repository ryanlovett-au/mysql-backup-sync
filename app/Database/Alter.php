<?php

namespace App\Database;

class Alter
{
	public static function parse_create_table(string $create_table): array|string
    {
        $lines = preg_split("/\r\n|\n|\r/", $create_table); // Split into lines
        $table_name = '';
        $columns = [];
        $indexes = [];
        $foreign_keys = [];
        $options = [];
        $parsing_columns = false;

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || str_starts_with($line, '/*')) {
                continue;
            }

            // Extract table name
            if (preg_match("/CREATE TABLE `([^`]+)`/", $line, $matches)) {
                $table_name = $matches[1];
                
                $parsing_columns = true; // Start parsing column definitions
                
                continue;
            }

            if (!$parsing_columns) continue;

            // Stop parsing columns at the end.
            if (str_starts_with($line, ')') ) {
                $parsing_columns = false;
                
                continue;
            }


            // Parse column definitions
            if (preg_match("/`([^`]+)` (.*?),?$/", $line, $matches)) {
                $column_name = $matches[1];
                
                $column_details = $matches[2];

                $columns[$column_name] = self::parse_column_details($column_details);

                if (is_string($columns[$column_name])) {
                    return $columns[$column_name]; // Return the error message
                }

                continue;
            }

            // Parse primary key
            if (preg_match("/PRIMARY KEY \(([^)]+)\)/", $line, $matches)) {
                $index_columns = array_map(function ($c) { return trim(str_replace('`', '', $c)); }, explode(',', $matches[1]));
                
                $indexes['PRIMARY'] = [ // Use 'PRIMARY' as the key for the primary key
                    'type' => 'PRIMARY KEY',
                    'columns' => $index_columns,
                    'name' => 'PRIMARY' // Add a name for consistency
                ];
                
                continue;
            }

            // Parse unique key
            if (preg_match("/UNIQUE KEY `([^`]+)` \(([^)]+)\)/", $line, $matches)) {
                $index_name = $matches[1];
                
                $index_columns = array_map(function ($c) { return trim(str_replace('`', '', $c)); }, explode(',', $matches[2]));
                
                $indexes[$index_name] = [
                    'type' => 'UNIQUE',
                    'columns' => $index_columns,
                    'name' => $index_name
                ];
                
                continue;
            }
            // Parse index key
            if (preg_match("/KEY `([^`]+)` \(([^)]+)\)/", $line, $matches)) {
                $index_name = $matches[1];
                
                $index_columns = array_map(function ($c) { return trim(str_replace('`', '', $c)); }, explode(',', $matches[2]));
                
                $indexes[$index_name] = [
                    'type' => 'INDEX',
                    'columns' => $index_columns,
                    'name' => $index_name
                ];
                
                continue;
            }

            // Parse fulltext index
            if (preg_match("/FULLTEXT KEY `([^`]+)` \(([^)]+)\)/", $line, $matches)) {
                $index_name = $matches[1];
                
                $index_columns = array_map(function ($c) { return trim(str_replace('`', '', $c)); }, explode(',', $matches[2]));
                
                $indexes[$index_name] = [
                    'type' => 'FULLTEXT',
                    'columns' => $index_columns,
                    'name' => $index_name
                ];
                
                continue;
            }

            // Parse foreign key constraints
            if (preg_match("/CONSTRAINT `([^`]+)` FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)` \(`([^`]+)`\)(.*)/", $line, $matches)) {
                $constraint_name = $matches[1];
                
                $column_name = $matches[2];
                
                $referenced_table = $matches[3];
                
                $referenced_column = $matches[4];
                
                $rest_of_line = $matches[5];

                $foreign_keys[$constraint_name] = [
                    'column'            => $column_name,
                    'referenced_table'  => $referenced_table,
                    'referenced_column' => $referenced_column,
                    'on_delete'         => 'NO ACTION',
                    'on_update'         => 'NO ACTION',
                ];

                // Parse ON DELETE and ON UPDATE actions
                if (strpos($rest_of_line, 'ON DELETE') !== false) {
                    if (preg_match("/ON DELETE (CASCADE|SET NULL|NO ACTION|RESTRICT|SET DEFAULT)/", $rest_of_line, $action_match)) {
                        $foreign_keys[$constraint_name]['on_delete'] = $action_match[1];
                    }
                }

                if (strpos($rest_of_line, 'ON UPDATE') !== false) {
                    if (preg_match("/ON UPDATE (CASCADE|SET NULL|NO ACTION|RESTRICT|SET DEFAULT)/", $rest_of_line, $action_match)) {
                        $foreign_keys[$constraint_name]['on_update'] = $action_match[1];
                    }
                }

                continue;
            }

            // Parse table options (ENGINE, CHARSET, COLLATE, etc.)
            if (preg_match("/(ENGINE|DEFAULT CHARSET|COLLATE|AUTO_INCREMENT|COMMENT)=(.*?)( |$)/i", $line, $matches)) {
                $option_name = strtoupper(trim($matches[1])); // Use strtoupper for consistency
                
                $option_value = trim(str_replace(['=', ';', ','], '', $matches[2]));
                
                $options[$option_name] = $option_value;
                
                continue;
            }
        }
        return [
            'columns'     => $columns,
            'indexes'     => $indexes,
            'foreignKeys' => $foreign_keys,
            'options'     => $options,
        ];
    }

    private static function parse_column_details(string $column_details): array|string
    {
        $details = [];
        $parts = explode(' ', $column_details);
        $parts = array_map('trim', $parts); //important

        $type = '';
        $nullable = true;
        $default = null;
        $extra = '';
        $comment = '';

        foreach ($parts as $part) {
            $part = trim($part);
            
            if (empty($part)) {
            	continue;
            }

            $part_upper = strtoupper($part); //for comparison

            // Data type (e.g., INT, VARCHAR(255), DATE)
            if (preg_match("/^[A-Za-z]+(\(\d+(,\s*\d+)?\))?$/", $part)) {
                $type = $part;
            } elseif ($part_upper === 'NOT') {
                $nullable = false;
            } elseif ($part_upper === 'NULL') {
                $nullable = true; //explicitly set to true
            } elseif ($part_upper === 'DEFAULT') {
                // Handle default values, including quoted strings
                $default = '';
                
                $next_part_index = array_search($part, $parts) + 1;
                
                if ($next_part_index < count($parts)) {
                    $next_part = $parts[$next_part_index];
                    
                    if (str_starts_with($next_part, "'") || str_starts_with($next_part, '"')) {
                        //grab until the ending quote.
                        $quote_char = $next_part[0];
                        
                        $default .= $next_part;
                        
                        $next_part_index++;
                        
                        while ($next_part_index < count($parts)) {
                            $next_part = $parts[$next_part_index];
                            
                            $default .= ' ' . $next_part;
                            
                            if (str_ends_with($next_part, $quote_char))
                                break;
                            
                            $next_part_index++;
                        }
                    }
                    else {
                        $default = $parts[$next_part_index];
                    }
                }
            } elseif ($part_upper === 'AUTO_INCREMENT') {
                $extra = 'AUTO_INCREMENT';
            }  elseif ($part_upper === 'COMMENT') {
                 $comment = '';
                 
                 $next_part_index = array_search($part, $parts) + 1;
                 
                 if ($next_part_index < count($parts)) {
                     $comment = $parts[$next_part_index];
                     
                     //grab until the ending quote
                     $quote_char = $comment[0];
                     
                     $next_part_index++;
                     
                     while ($next_part_index < count($parts)) {
                          $next_part = $parts[$next_part_index];
                          
                          $comment .= ' ' . $next_part;
                          
                          if (str_ends_with($next_part, $quote_char))
                              break;
                          
                          $next_part_index++;
                      }
                  }
            }
            //ignore UNSIGNED and other modifiers

        }

        return [
            'type'     => $type,
            'nullable' => $nullable,
            'default'  => $default,
            'extra'    => $extra,
            'comment'  => trim(str_replace("'",'', $comment)), //remove quotes
        ];
    }

}