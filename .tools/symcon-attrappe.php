<?php
/**
 * Symcon-Attrappe für Modultests ohne Symcon: IPSModule mit Eigenschaften, Attributen, Timern, Variablen,
 * Medien und Sperren sowie die IPS_*-Funktionen, die die Module der AirPlay-Steuerung benutzen.
 * Nur so viel Verhalten wie nötig; alles andere wirft „Attrappe: <Name>", damit Lücken auffallen.
 */
declare(strict_types=1);

const IS_ACTIVE = 102;
const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const KL_MESSAGE = 10200;
const VM_UPDATE = 10603;
const KL_WARNING = 10201;
const MEDIATYPE_IMAGE = 1;
const MEDIATYPE_SOUND = 2;
const OBJECTTYPE_MEDIA = 5;
const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_STRING = 3;
const VARIABLE_PRESENTATION_VALUE_PRESENTATION = 'value';
const VARIABLE_PRESENTATION_ENUMERATION = 'enum';
const VARIABLE_PRESENTATION_SLIDER = 'slider';
const VARIABLE_PRESENTATION_SWITCH = 'switch';

final class Attrappe
{
    /** @var array<int, array{typ:string, ident:string, name:string, parent:int, wert:mixed, versteckt:bool}> */
    public static array $objekte = [];
    /** @var array<int, IPSModule> */
    public static array $instanzen = [];
    public static int $naechste = 50000;
    /** @var array<string, string> Klassenname → Modul-GUID (für IPS_GetInstanceListByModuleID) */
    public static array $module = [];
    /** Antwort von Sys_GetNetworkInfo */
    public static array $netz = [];

    public static function zuruecksetzen(): void
    {
        self::$objekte = [];
        self::$instanzen = [];
        self::$naechste = 50000;
    }

    public static function neu(string $typ, int $parent, string $ident = '', mixed $wert = null): int
    {
        $id = self::$naechste++;
        self::$objekte[$id] = ['typ' => $typ, 'ident' => $ident, 'name' => '', 'parent' => $parent, 'wert' => $wert, 'versteckt' => false, 'geaendert' => time()];
        return $id;
    }

    public static function kind(int $parent, string $ident): int|false
    {
        foreach (self::$objekte as $id => $o) {
            if ($o['parent'] === $parent && $o['ident'] === $ident) {
                return $id;
            }
        }
        return false;
    }
}

class IPSModule
{
    public int $InstanceID;
    public int $status = 0;
    public string $summary = '';
    /** @var array<string, int> */
    public array $timer = [];
    /** @var list<string> */
    public array $log = [];
    /** @var list<string> */
    public array $kachel = [];
    private array $eigenschaften = [];
    private array $attribute = [];
    private array $puffer = [];
    /** @var array<int, list<int>> Sender → Meldungen (RegisterMessage) */
    public array $meldungen = [];

    public function __construct($InstanceID)
    {
        $this->InstanceID = (int)$InstanceID;
        Attrappe::$instanzen[$this->InstanceID] = $this;
    }

    public function Create()
    {
    }

    public function ApplyChanges()
    {
    }

    public function setzen(string $name, mixed $wert): void
    {
        $this->eigenschaften[$name] = $wert;
    }

    public function eigenschaft(string $name): mixed
    {
        return $this->eigenschaften[$name];
    }

    public function __call($name, $args)
    {
        return match (true) {
            str_starts_with($name, 'RegisterProperty') => $this->eigenschaften[$args[0]] = $args[1],
            str_starts_with($name, 'RegisterAttribute') => $this->attribute[$args[0]] = $args[1],
            str_starts_with($name, 'ReadProperty') => $this->eigenschaften[$args[0]],
            str_starts_with($name, 'ReadAttribute') => $this->attribute[$args[0]],
            str_starts_with($name, 'WriteAttribute') => $this->attribute[$args[0]] = $args[1],
            $name === 'GetBuffer' => $this->puffer[$args[0]] ?? '',
            $name === 'SetBuffer' => $this->puffer[$args[0]] = $args[1],
            $name === 'RegisterTimer' => $this->timer[$args[0]] = 0,
            $name === 'SetTimerInterval' => $this->timer[$args[0]] = $args[1],
            $name === 'Translate' => $args[0],
            $name === 'SetStatus' => $this->status = $args[0],
            $name === 'SetSummary' => $this->summary = $args[0],
            $name === 'LogMessage' => $this->log[] = $args[0],
            $name === 'UpdateVisualizationValue' => $this->kachel[] = $args[0],
            $name === 'MaintainVariable' => Attrappe::kind($this->InstanceID, $args[0]) ?: Attrappe::neu('var', $this->InstanceID, $args[0]),
            $name === 'GetIDForIdent' => Attrappe::kind($this->InstanceID, $args[0]),
            $name === 'SetValue' => Attrappe::$objekte[Attrappe::kind($this->InstanceID, $args[0])]['wert'] = $args[1],
            $name === 'RegisterMessage' => $this->meldungen[$args[0]][] = $args[1],
            $name === 'UnregisterMessage' => $this->meldungen[$args[0]] = array_values(array_diff($this->meldungen[$args[0]] ?? [], [$args[1]])),
            $name === 'GetMessageList' => array_filter($this->meldungen),
            in_array($name, ['EnableAction', 'SendDebug', 'SetVisualizationType', 'UpdateFormField'], true) => null,
            default => throw new RuntimeException("Attrappe: $name"),
        };
    }
}

function GetValue($id) { return Attrappe::$objekte[$id]['wert']; }
function GetValueFormatted($id) { return (string)Attrappe::$objekte[$id]['wert']; }
function IPS_VariableExists($id) { return (Attrappe::$objekte[$id]['typ'] ?? '') === 'var'; }
function IPS_InstanceExists($id) { return isset(Attrappe::$instanzen[$id]); }
function IPS_GetInstance($id) { return ['ModuleInfo' => ['ModuleID' => Attrappe::$module[get_class(Attrappe::$instanzen[$id])] ?? '']]; }
function IPS_MediaExists($id) { return (Attrappe::$objekte[$id]['typ'] ?? '') === 'media'; }
function IPS_GetObjectIDByIdent($ident, $parent) { return Attrappe::kind((int)$parent, $ident); }
function IPS_GetChildrenIDs($parent) { return array_keys(array_filter(Attrappe::$objekte, fn ($o) => $o['parent'] === $parent)); }
function IPS_GetObject($id) { $o = Attrappe::$objekte[$id]; return ['ObjectType' => $o['typ'] === 'media' ? OBJECTTYPE_MEDIA : 2, 'ObjectIdent' => $o['ident'], 'ObjectName' => $o['name'], 'ObjectIsHidden' => $o['versteckt']]; }
function IPS_GetName($id) { return Attrappe::$objekte[$id]['name'] ?? ('Instanz ' . $id); }
function IPS_CreateMedia($typ) { return Attrappe::neu('media', 0, '', ''); }
function IPS_SetParent($id, $p) { Attrappe::$objekte[$id]['parent'] = $p; }
function IPS_SetIdent($id, $i) { Attrappe::$objekte[$id]['ident'] = $i; }
function IPS_SetName($id, $n) { Attrappe::$objekte[$id]['name'] = $n; }
function IPS_SetHidden($id, $v) { Attrappe::$objekte[$id]['versteckt'] = $v; }
function IPS_SetPosition($id, $p) {}
function IPS_SetMediaCached($id, $v) {}
function IPS_SetMediaFile($id, $f, $v) {}
function IPS_SetMediaContent($id, $c) { Attrappe::$objekte[$id]['wert'] = $c; Attrappe::$objekte[$id]['geaendert'] = time(); }
// Wie Symcon: Ohne Datei (neues Medium, noch kein Inhalt) warnt IPS_GetMediaContent — die Attrappe wirft, damit es auffällt.
function IPS_GetMediaContent($id) { if (Attrappe::$objekte[$id]['wert'] === '') { throw new RuntimeException("Attrappe: Datei von Medium $id existiert nicht"); } return Attrappe::$objekte[$id]['wert']; }
function IPS_GetMedia($id) { return ['MediaUpdated' => Attrappe::$objekte[$id]['geaendert'], 'MediaIsAvailable' => Attrappe::$objekte[$id]['wert'] !== '']; }
function IPS_DeleteMedia($id, $datei) { unset(Attrappe::$objekte[$id]); return true; }
function IPS_SemaphoreEnter($n, $t) { return true; }
function IPS_SemaphoreLeave($n) { return true; }
function IPS_GetInstanceListByModuleID($guid) { return array_keys(array_filter(Attrappe::$instanzen, fn ($i) => (Attrappe::$module[get_class($i)] ?? '') === $guid)); }
function IPS_GetProperty($id, $name) { return Attrappe::$instanzen[$id]->eigenschaft($name); }
function IPS_GetKernelRunlevel() { return KR_READY; }
function Sys_GetNetworkInfo() { return Attrappe::$netz; }
