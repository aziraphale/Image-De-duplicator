<?php

// Set the path to the directory of images to examine
define('TARGET_DIR', '/path/to/image/directory');

// And the path to use in the output HTML to display the images
define('HTTP_IMG_PATH', './imgs');

// Configure a connection to a database
$pdo = new PDO("mysql:host=localhost;dbname=img_comp", "root", "");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

set_time_limit(0);
ini_set('memory_limit', '1G');

class Img {
    // These define the resolution of the image comparison. Higher numbers will result in better
    //  accuracy at the expense of CPU time and (a lot of) memory
    const COMP_W = 5;
    const COMP_H = 5;
    
    public $name;
    public $size;
    public $hash;
    public $isImage = false;
    public $w = 0;
    public $h = 0;
    public $pixels = array();
    
    public function __construct($name) {
        $this->name = $name;
        $this->size = filesize($name);
        $this->hash = md5_file($name);
        
        if ($info = getimagesize($name)) {
            if (in_array($info[2], array(IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF))) {
                $this->isImage = true;
                switch ($info[2]) {
                    case IMAGETYPE_PNG:
                        $img = imagecreatefrompng($name);
                        break;
                    case IMAGETYPE_JPEG:
                        $img = imagecreatefromjpeg($name);
                        break;
                    case IMAGETYPE_GIF:
                        $img = imagecreatefromgif($name);
                        break;
                }
                
                if ($img) {
                    $this->w = imagesx($img);
                    $this->h = imagesy($img);
                    
                    $comp = imagecreatetruecolor(self::COMP_W, self::COMP_H);
                    imagecopyresampled($comp, $img, 0, 0, 0, 0, self::COMP_W, self::COMP_H, $this->w, $this->h);
                    imagedestroy($img);
                    
                    for ($row=0; $row<self::COMP_H; ++$row) {
                        for ($col=0; $col<self::COMP_W; ++$col) {
                            $this->pixels[] = imagecolorat($comp, $col, $row);
                        }
                    }
                }
            }
        }
    }
}

class Comp {
    public $a;
    public $b;
//    public $aName;
//    public $bName;
    public $sizesMatch = false;
    public $hashesMatch = false;
    public $dimensionsMatch = false;
    public $bothImages = false;
    public $pixelDiffs = array();
    public $avgPixelDiff;
    
    public function __construct(Img $a, Img $b) {
        $this->a = $a;
        $this->b = $b;
        
//        $this->aName = $a->name;
//        $this->bName = $b->name;
        
        $this->sizesMatch = ($a->size == $b->size);
        $this->hashesMatch = ($a->hash == $b->hash);
        
        if ($a->isImage && $b->isImage) {
            $this->bothImages = true;
            $this->dimensionsMatch = ($a->w == $b->w && $a->h == $b->h);
            
            foreach ($a->pixels as $k => $v) {
                $this->pixelDiffs[] = abs($v - $b->pixels[$k]);
            }
            
            $this->avgPixelDiff = round(array_sum($this->pixelDiffs) / count($this->pixelDiffs));
        }
    }
}

function htmlq($s) { return htmlspecialchars($s, ENT_QUOTES); }

if (!isset($_SERVER['REMOTE_ADDR'])) {
    echo "No IP address detected; performing initial data load...\r\n";
    
    $pdo->exec("TRUNCATE image; TRUNCATE comp;");
    
    chdir(TARGET_DIR);
    $files = array();
    echo "Loading files...\r\n";
    $stmt = $pdo->prepare("INSERT INTO image (name,size,hash,isimage,w,h,pixels) VALUES (?,?,?,?,?,?,?)");
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.')) as /** @var SplFileInfo */ $f) {
        if ($f->isDir()) continue;
        echo '[Loaded] ' . $f->getPathname() . "\r\n";
        $newFile = new Img($f->getPathname());
        $files[] = $newFile;
        
        $stmt->execute(array($newFile->name, $newFile->size, $newFile->hash, $newFile->isImage, $newFile->w, $newFile->h, serialize($newFile->pixels)));
        
//        if ($i++ == 5) break;
    }
    
    echo "Comparing files...\r\n";
    $tested = array();
    $comparisons = array();
    $stmt = $pdo->prepare("INSERT INTO comp (a,b,pixeldiffs,avgpixeldiff) VALUES (?,?,?,?)");
    foreach ($files as $v) {
        foreach ($files as $vv) {
            if ($v->name == $vv->name) continue;
            if (in_array($vv->name, $tested, true)) continue;
            $c = new Comp($v, $vv);
            if (($c->bothImages && ($c->dimensionsMatch || $c->avgPixelDiff<= 50000)) || $c->sizesMatch) {
                $stmt->execute(array($v->name, $vv->name, serialize($c->pixelDiffs), $c->avgPixelDiff));
                echo '[Compared][X] ' . $v->name . ' vs. ' . $vv->name . "\r\n";
            } else {
                echo '[Compared][ ] ' . $v->name . ' vs. ' . $vv->name . "\r\n";
            }
//            break 2;
        }
        $tested[] = $v->name;
    }
    
    echo "Done!\r\n";
} else {
    $stmt = $pdo->prepare("SELECT ia.name AS aname, ib.name AS bname, ia.size AS asize, ib.size AS bsize, ia.hash AS ahash, ib.hash AS bhash, ia.isimage AS aisimage, ib.isimage AS bisimage, ia.w AS aw, ib.w AS bw, ia.h AS ah, ib.h AS bh, c.avgpixeldiff FROM comp AS c LEFT JOIN image AS ia ON c.a=ia.name LEFT JOIN image AS ib ON c.b=ib.name ORDeR BY c.avgpixeldiff ASC LIMIT 50 OFFSET 0");
    $stmt->execute();
    
    // Pass "?rm" to the URL to generate a list of arguments to a `rm` call (that you can
    //  manually edit, if necessary, before running the command)
    if (isset($_GET['rm'])) {
        header("Content-Type: text/plain");
        $i = 0;
        while ($v = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo escapeshellarg($v['bname']) . ' ';
            if (++$i == 20) {
                echo "\r\n\r\n\r\n";
                $i = 0;
            }
        }
        die();
    }
    ?>
    <style>
    img {
        max-width: 400px;
        max-height: 400px;
    }
    </style>
    <table>
        <thead>
            <tr>
                <th>First Filename</th>
                <th>Second Filename</th>
                <th>First Size</th>
                <th>Second Size</th>
                <th>Sizes Match?</th>
                <th>Hashes Match?</th>
                <th>Both Images?</th>
                <th>Average Pixel Diff</th>
            </tr>
        </thead>
        <tbody>
            <? while ($v = $stmt->fetch(PDO::FETCH_ASSOC)) { ?>
                <tr>
                    <td><img src="<?=htmlq(HTTP_IMG_PATH)?>/<?=htmlq($v['aname'])?>"><br><?=$v['aname']?></td>
                    <td><img src="<?=htmlq(HTTP_IMG_PATH)?>/<?=htmlq($v['bname'])?>"><br><?=$v['bname']?></td>
                    <td><?=$v['aw']?>x<?=$v['ah']?></td>
                    <td><?=$v['bw']?>x<?=$v['bh']?></td>
                    <td><?= ($v['asize']==$v['bsize']) ? ' YES ' : '' ?></td>
                    <td><?= ($v['ahash']==$v['bhash']) ? ' YES ' : '' ?></td>
                    <td>YES</td>
                    <td><?=round($v['avgpixeldiff'], 7)?></td>
                </tr>
            <? } ?>
        </tbody>
    </table>
    <?
}

?>