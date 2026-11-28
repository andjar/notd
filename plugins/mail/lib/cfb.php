<?php
// Reading Compound File Binary files (MS-CFB), the container an Outlook .msg
// file is: a small file system of storages (folders) and streams (files)
// inside one file. Read-only, and wary: a chain of sectors that loops or points
// outside the file stops with an error instead of running on.
//
//     $cfb = new Cfb(file_get_contents('message.msg'));
//     foreach ($cfb->children() as $name => $entry) ...    // the root storage's entries
//     $bytes = $cfb->stream($cfb->children()['__PROPERTIES_VERSION1.0']);

declare(strict_types=1);

final class Cfb
{
    public const STORAGE = 1;
    public const STREAM = 2;
    private const LAST = 0xFFFFFFFA;          // sector numbers above this mean "end of chain", "free" and so on
    private const NONE = 0xFFFFFFFF;

    private int $sectorSize;
    private int $miniSize;
    private int $cutoff;
    /** @var list<int> */
    private array $fat = [];
    /** @var list<int> */
    private array $miniFat = [];
    private string $miniStream = '';
    /** @var list<array{name: string, type: int, left: int, right: int, child: int, start: int, size: int}> */
    private array $entries = [];

    public function __construct(private string $data)
    {
        if (strlen($data) < 512 || !str_starts_with($data, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            throw new RuntimeException('This is not an Outlook message (.msg) file.');
        }
        $h = unpack('vminor/vmajor/vorder/vshift/vminiShift', $data, 24);
        $f = unpack('VdirSectors/VfatSectors/VdirStart/Vtx/Vcutoff/VminiFatStart/VminiFatSectors/VdifatStart/VdifatSectors', $data, 40);
        if ($h['order'] !== 0xFFFE || !in_array($h['shift'], [9, 12], true) || $h['miniShift'] !== 6) {
            throw new RuntimeException('This .msg file is damaged (its header cannot be read).');
        }
        $this->sectorSize = 1 << $h['shift'];
        $this->miniSize = 1 << $h['miniShift'];
        $this->cutoff = $f['cutoff'];

        // The FAT: its sectors are listed in the header (109) and then in a chain of DIFAT sectors.
        $fatSectors = array_slice(array_values(unpack('V109', $data, 76)), 0, $f['fatSectors']);
        $per = $this->sectorSize / 4;
        for ($s = $f['difatStart'], $n = 0; $s <= self::LAST && count($fatSectors) < $f['fatSectors']; $n++) {
            if ($n > $f['difatSectors']) {
                throw new RuntimeException('This .msg file is damaged (its sector table loops).');
            }
            $list = array_values(unpack("V$per", $this->sector($s)));
            $s = array_pop($list);
            array_push($fatSectors, ...$list);
        }
        foreach (array_slice($fatSectors, 0, $f['fatSectors']) as $s) {
            array_push($this->fat, ...array_values(unpack("V$per", $this->sector($s))));
        }
        if ($f['miniFatSectors'] > 0) {
            $this->miniFat = array_values(unpack('V*', $this->read($f['miniFatStart'], $this->fat, $this->sectorSize, fn($s) => $this->sector($s))));
        }

        $dir = $this->read($f['dirStart'], $this->fat, $this->sectorSize, fn($s) => $this->sector($s));
        for ($i = 0; $i + 128 <= strlen($dir); $i += 128) {
            $e = unpack('vlen/Ctype/Ccolor/Vleft/Vright/Vchild', $dir, $i + 64);
            $start = unpack('Vstart/Vsize', $dir, $i + 116);
            $name = $e['len'] >= 2 ? mb_convert_encoding(substr($dir, $i, min(64, $e['len']) - 2), 'UTF-8', 'UTF-16LE') : '';
            $this->entries[] = ['name' => $name, 'type' => $e['type'], 'left' => $e['left'], 'right' => $e['right'],
                'child' => $e['child'], 'start' => $start['start'], 'size' => $start['size']];
        }
        if (($this->entries[0]['type'] ?? 0) !== 5) {
            throw new RuntimeException('This .msg file is damaged (it has no root storage).');
        }
        $root = $this->entries[0];
        $this->miniStream = substr($this->read($root['start'], $this->fat, $this->sectorSize, fn($s) => $this->sector($s)), 0, $root['size']);
    }

    /** The entries in a storage (0 is the root): [upper-case name => entry number]. */
    public function children(int $storage = 0): array
    {
        $out = [];
        $seen = [];
        $walk = function (int $i) use (&$walk, &$out, &$seen): void {
            if ($i === self::NONE || !isset($this->entries[$i]) || isset($seen[$i])) {
                return;
            }
            $seen[$i] = true;
            $e = $this->entries[$i];
            $walk($e['left']);
            $out[mb_strtoupper($e['name'])] = $i;
            $walk($e['right']);
        };
        $walk($this->entries[$storage]['child'] ?? self::NONE);
        return $out;
    }

    public function type(int $entry): int
    {
        return $this->entries[$entry]['type'] ?? 0;
    }

    /** A stream's bytes. */
    public function stream(int $entry): string
    {
        $e = $this->entries[$entry] ?? null;
        if ($e === null || $e['type'] !== self::STREAM) {
            throw new RuntimeException('This .msg file is damaged (a stream is missing).');
        }
        if ($e['size'] < $this->cutoff) {
            $bytes = $this->read($e['start'], $this->miniFat, $this->miniSize, fn($s) => substr($this->miniStream, $s * $this->miniSize, $this->miniSize));
        } else {
            $bytes = $this->read($e['start'], $this->fat, $this->sectorSize, fn($s) => $this->sector($s));
        }
        return substr($bytes, 0, $e['size']);
    }

    /** Sector $s of the file. */
    private function sector(int $s): string
    {
        $at = ($s + 1) * $this->sectorSize;
        if ($s > self::LAST || $at + $this->sectorSize > strlen($this->data) + $this->sectorSize) {
            throw new RuntimeException('This .msg file is damaged (a sector lies outside it).');
        }
        return str_pad(substr($this->data, $at, $this->sectorSize), $this->sectorSize, "\0");
    }

    /** The bytes of a chain of (mini) sectors from $start, following $table. */
    private function read(int $start, array $table, int $size, callable $get): string
    {
        $out = '';
        $count = 0;
        for ($s = $start; $s <= self::LAST; $s = $table[$s] ?? self::NONE) {
            if (++$count > count($table) + 1) {
                throw new RuntimeException('This .msg file is damaged (a chain of sectors loops).');
            }
            $out .= $get($s);
        }
        return $out;
    }
}
