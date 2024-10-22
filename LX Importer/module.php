<?php

declare(strict_types=1);
    class LXImporter extends IPSModule
    {
        public const ROLE_MAPPINGS = [
            'OnOff' => [
                'Aliases' => ['SchaltenOnOff'],
                'Type' => 1,
                'Dimension' => 1,
                'Tag' => 'lighting',
                'SubTag' => '',
                'ReturnRoles' => ['StatusOnOff', 'status@OnOff'],
            ],
            'Dimmen%' => [
                'Type' => 5,
                'Dimension' => 1,
                'Tag' => 'lighting',
                'SubTag' => '',
                'ReturnRoles' => ['Status%'],
            ],
            'Höhe%' => [
                'Type' => 5,
                'Dimension' => 1,
                'Tag' => 'shading',
                'SubTag' => '',
                'ReturnRoles' => ['StatusHöhe%'],
            ],
            'Lamelle%' => [
                'Type' => 5,
                'Dimension' => 1,
                'Tag' => 'shading',
                'SubTag' => 'lamella',
                'ReturnRoles' => ['StatusLamelle%'],
            ],
        ];

        public function Create()
        {
            //Never delete this line!
            parent::Create();
            $this->RegisterPropertyString('ImportFile', '');
        }

        public function Destroy()
        {
            //Never delete this line!
            parent::Destroy();
        }

        public function ApplyChanges()
        {
            //Never delete this line!
            parent::ApplyChanges();
        }

        public function GetConfigurationForm(): string
        {
            $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            if ($this->UIValidateImport($this->ReadPropertyString('ImportFile'))) {
                $data['actions'][0]['values'] = $this->createConfiguratorValues($this->ReadPropertyString('ImportFile'));
            }
            return json_encode($data);
        }

        public function UIValidateImport($File)
        {
            if (strlen($File) == 0) {
                return false;
            }
            return true;
        }

        private function createConfiguratorValues(String $File)
        {
            $xml = simplexml_load_string(base64_decode($File), null, LIBXML_NOCDATA);
            $array = json_decode(json_encode($xml), true);
            $values = [];
            $id = 1;
            $ids = [];
            $parents = [];
            $this->SendDebug('Import', json_encode($array), 0);
            $getDevice = function ($valueId, $currentValues) {
                $this->SendDebug('VALUE', print_r($currentValues, true), 0);
                foreach ($currentValues as $value) {
                    if ($value['id'] == $valueId) {
                        return $value;
                    }
                }
                return false;
            };

            //create floors
            foreach ($array['floors'] as $floor) {
                $this->SendDebug('Floor', json_encode($floor), 0);
                $floorID = $id++;
                $values[] = [
                    'name' => $floor['@attributes']['name'],
                    'parent' => 0,
                    'id' => $floorID,
                ];
                foreach ($floor['room'] as $room) {
                    $roomID = $id++;
                    $ids[$room['@attributes']['id']] = $roomID;
                    $values[] = [
                        'name' => $room['@attributes']['name'],
                        'parent' => $floorID,
                        'id' => $roomID
                    ];
                    $this->SendDebug('FLOOR ' . $floor['@attributes']['name'], $room['@attributes']['name'], 0);
                    if ($room['@attributes']['name'] == 'Haustüre') {
                        $this->SendDebug('HAUSTÜRE', json_encode($room), 0);
                        $this->SendDebug('HAUSTÜRE', json_encode($room['function']['actuator-ref']['@attributes']['ref']), 0);
                    }

                    if (array_key_exists('function', $room)) {
                        $this->SendDebug('function length', print_r(sizeof($room['function']), true), 0);
                        foreach ($room['function'] as $function) {
                            $this->SendDebug('function', print_r(array_keys($function), true), 0);
                            if (array_key_exists('actuator-ref', $function)) {
                                $this->SendDebug($room['@attributes']['name'], $function['actuator-ref']['@attributes']['ref'], 0);
                                $parents[$function['actuator-ref']['@attributes']['ref']] = $roomID;
                            } else {
                                // I don't know why
                                if (isset($room['function']['actuator-ref'])) {
                                    $parents[$room['function']['actuator-ref']['@attributes']['ref']] = $roomID;
                                }
                                $this->SendDebug($room['@attributes']['name'] . 'ELSE', print_r($function, true), 0);
                            }
                        }
                    }
                }
            }

            foreach ($array['devices']['device'] as $device) {
                if (array_key_exists('actuator', $device)) {
                    foreach ($device['actuator'] as $actuator) {
                        if (array_key_exists('@attributes', $actuator)) {
                            $device =
                            [
                                'name' => $actuator['@attributes']['name'],
                                'parent' => $parents[$actuator['@attributes']['id']] ?? 0,
                                'id' => $id++,
                                'instanceID' => 0,
                            ];
                            if (array_key_exists('datapoint', $actuator)) {
                                $groupAddresses = [];
                                $getGa = function ($datapoints, $roles) {
                                    foreach ($datapoints as $datapoint) {
                                        if (in_array($datapoint['@attributes']['role'], $roles)) {
                                            return  $this->splitGroupAddress($datapoint['@attributes']['address']);
                                        }
                                    }
                                    return [];
                                };
                                foreach (self::ROLE_MAPPINGS as $role => $roleMap) {
                                    $aliases = array_key_exists('Aliases', $roleMap) ? $roleMap['Aliases'] : [];
                                    $aliases[] = $role;
                                    $mainAddresses = $getGa($actuator['datapoint'], $aliases);
                                    $groupAddressConfig = [];
                                    if ($mainAddresses) {
                                        $device['ga'] = implode('/', $mainAddresses);
                                        $groupAddressConfig = [
                                            'Address1' => $mainAddresses[0],
                                            'Address2' => $mainAddresses[1],
                                            'Address3' => $mainAddresses[2],
                                            // May be overwritten at a later point by ROLE_MAPPINGS
                                            'Mapping' => [],
                                            'CapabilityRead' => false,
                                            'CapabilityWrite' => true,
                                            'CapabilityReceive' => true,
                                            'CapabilityTransmit' => false,
                                            'EmulateStatus' => true,
                                        ];
                                        if (array_key_exists('ReturnRoles', $roleMap)) {
                                            $returnAddresses = $getGa($actuator['datapoint'], $roleMap['ReturnRoles']);
                                            if ($returnAddresses) {
                                                $device['ga'] .= ', ' . implode('/', $returnAddresses);

                                                $groupAddressConfig['Mapping'] = [[
                                                    'Address1' => $returnAddresses[0],
                                                    'Address2' => $returnAddresses[1],
                                                    'Address3' => $returnAddresses[2],
                                                ]];
                                            }
                                        }
                                        foreach ($roleMap as $key => $value) {
                                            if ($key != 'ReturnRoles') {
                                                $groupAddressConfig[$key] = $value;
                                            }
                                        }
                                        $groupAddresses[] = $groupAddressConfig;
                                    }
                                }
                                if ($groupAddresses) {
                                    $device['create'] = [
                                        'moduleID' => '{FB223058-3084-C5D0-C7A2-3B8D2E73FE8A}',
                                        'configuration' => [
                                            'GroupAddresses' => json_encode($groupAddresses)
                                        ]
                                    ];
                                    $location = [];
                                    $currentDevice = $device;
                                    while ($currentDevice['parent'] != 0) {
                                        $parent = $getDevice($currentDevice['parent'], $values);
                                        if ($parent) {
                                            $location[] = $parent['name'];
                                            $currentDevice = $parent;
                                        }
                                    }
                                    if ($location) {
                                        $device['create']['location'] = array_reverse($location);
                                    }
                                    $values[] = $device;
                                }
                            }
                        }
                    }
                }
            }
            return $values;
        }

        private function splitGroupAddress($ga)
        {
            return [($ga >> (8 + 3)) & 0x1F, ($ga >> 8) & 0x07, $ga & 0xFF];
        }

        public function UIImport($File)
        {
            $this->UpdateFormField('Configurator', 'values', json_encode($this->createConfiguratorValues($File)));
        }

        private function createId($path, &$configurator, &$id)
        {
            $pathId =  $this->getID($path, $configurator);
            if ($pathId != 0) {
                return $pathId;
            } elseif (count($path) == 1) {
                $configurator[] = [
                    'name' => $path[0],
                    'id' => ++$id,
                    'parent' => 0,
                ];
                return $id;
            } else {
                $parentId = $this->createId(array_slice($path, 0, count($path) - 1), $configurator, $id);
                $configurator[] = [
                    'name' => $path[count($path) - 1],
                    'id' => ++$id,
                    'parent' => $parentId,
                ];
                return $id;
            }
        }


        private function createDevice(&$values, $attributes, String $type, &$id)
        {
            $device = [
                'address' => $attributes['Address_hex'],
                'name' => $path[count($path) - 1],
                'parent' => $parentId,
                'id' => ++$id,
                'type' => $attributes['Type'],
                'instanceID' => 0,
            ];
            $values[] = $device;
        }

        private function getID($path, $values)
        {
            $getID = function ($parent, $name) use ($values) {
                foreach ($values as $element) {
                    if ($element['parent'] == $parent && $element['name'] == $name) {
                        return $element['id'];
                    }
                }
                return 0;
            };
            $id = 0;
            foreach ($path as $name) {
                $id = $getID($id, $name);
            }
            return $id;
        }

        private function searchDevice($deviceID, $guid): int
        {
            $connectionID = IPS_GetInstance($this->InstanceID);
            $ids = IPS_GetInstanceListByModuleID($guid);
            foreach ($ids as $id) {
                $i = IPS_GetInstance($id);
                if (IPS_GetProperty($id, 'DeviceID') == $deviceID) {
                    return $id;
                }
            }
            return 0;
        }
    }
