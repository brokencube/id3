<?php
namespace brokencube\ID3;

class Reader
{
    /*
        http://id3.org/id3v2.3.0
    */
    
    protected $filename;
    protected $fp;
    protected $data;
    public function __construct($filename)
    {
        $this->filename = $filename;
    }

    protected function unpackSyncSafeInteger($bytes)
    {
        list(,$size) = unpack('N', $bytes);

        return ($size & 0x0000007F)
            + (($size & 0x00007F00) >> 1)
            + (($size & 0x007F0000) >> 2)
            + (($size & 0x7F000000) >> 3);
    }

    public function view()
    {
        $this->fp = fopen($this->filename,'r');
        
        $mainHeader = fread($this->fp, 10);
        // Check we have the expected magic string
        if (substr($mainHeader,0,3) != 'ID3') {
            return [];
        }
        
        $mainHeaderLength = $this->unpackSyncSafeInteger(substr($mainHeader, 6, 4));
        
        // Sanity check - if the decoded length value is larger than the file, we must be looking at junk data, bail out.
        if ($mainHeaderLength > filesize($this->filename)) {
            return [];
        }

        if ($mainHeaderLength < 10) {
            return [];
        }
        
        $versionNumber = ord(substr($mainHeader, 3, 1));
        $pointer = 10;
        
        $mainFlags = substr($mainHeader, 5, 1);
        $unsync = ord($mainFlags) & 0x80;
        
        if ($unsync) {
            $data = fread($this->fp, $mainHeaderLength * 1.05);
            fclose($this->fp);
            $this->fp = fopen('php://temp/maxmemory:'. (4*1024), 'w+');
            fwrite($this->fp, $mainHeader . $this->unsynchroniseString($data));
        }
        
        switch ($versionNumber) {
            case 2:
                return $this->decodeV2($mainHeaderLength, $pointer, $unsync);
            
            case 3:
                return $this->decodeV3($mainHeaderLength, $pointer, $unsync);
            
            case 4:
                return $this->decodeV4($mainHeaderLength, $pointer, $unsync);
        }
    }
    
    public function decodeV2($length, $pointer = 10, $unsync)
    {
        $tags = [];
        do {
            $tagEncoding = null;
            $tagDataExtra = null;
            $tagLang = null;
            
            fseek($this->fp, $pointer);
            
            // Read data for next tag
            $tagName = fread($this->fp, 3);
            $tagRawSize = fread($this->fp, 3);
            list(,$tagDataSize) = unpack('N', chr(0) . $tagRawSize);
            $data = $tagDataSize ? fread($this->fp, $tagDataSize) : '';
            
            // If we somehow end up with a dead resource (or stream past the end of the file etc.)
            if ($tagName === false) {
                return $tags;
            }
            
            // Have we hit the end padding?
            if (bin2hex($tagName) === '000000') {
                return $tags;
            }

            if ($unsync) {
                $data = $this->unsynchroniseString($data);
            }

            // Trim any nul chars off the data
            $data = $this->trimNull($data);
            
            // Do special things for the Com tag
            switch (true) {
                case $tagName == "COM":
                    $tagEncoding = ord(substr($data, 0, 1));
                    $tagLang = substr($data, 1, 3);
                    $data = $this->trimNull(substr($data, 4));
                    $tagDataExtra = $this->trimNull(substr($data, 0, strpos($data, chr(0))));
                    $tagData = substr($data, strpos($data, chr(0)));
                    $tagData = $this->decodeText($tagData, $tagEncoding);
                    break;
                
                case substr($tagName, 0, 1) == 'T':
                    $tagEncoding = ord(substr($data, 0, 1));
                    $tagData = $this->decodeText(substr($data, 1), $tagEncoding);
                    break;
                
                case $tagName == "TXX":
                    $tagEncoding = ord(substr($data, 0, 1));
                    $data = $this->trimNull(substr($data, 1));
                    $tagDataExtra = $this->trimNull(substr($data, 0, strpos($data, chr(0))));
                    $tagData = substr($data, strpos($data, chr(0)));
                    $tagData = $this->decodeText($tagData, $tagEncoding);
                    break;
                
                default:
                    $tagData = $data;
                    $tagLang = null;
                    break;
            }

            $tags[] = [
                'tagName' => $tagName,
                'tagData' => $tagData,
                'tagLang' => $tagLang,
                'tagDataExtra' => $tagDataExtra                
            ];
            
            $pointer += (6 + $tagDataSize);
            
        } while ($pointer < $length);
        return $tags;
    }
        
    public function decodeV3($length, $pointer, $unsync)
    {
        $tags = [];
        do {
            $tagEncoding = null;
            $tagDataExtra = null;
            $tagLang = null;
            
            fseek($this->fp, $pointer);
            
            // Read data for next tag
            $tagName = fread($this->fp, 4);
            $tagRawSize = fread($this->fp, 4);
            $tagStatusFlags = fread($this->fp, 1);
            $tagFormatFlags = fread($this->fp, 1);
            list(,$tagDataSize) = unpack('N', $tagRawSize);
            
            // If we somehow end up with a dead resource (or stream past the end of the file etc.)
            if ($tagName === false) {
                return $tags;
            }
            
            // Have we hit the end padding?
            if (bin2hex($tagName) === '00000000') {
                return $tags;
            }
            
            $data = $tagDataSize ? fread($this->fp, $tagDataSize) : '';
            
            // Decode Flags
            $frameFlags = $this->version3Frame($tagStatusFlags, $tagFormatFlags);
            
            // If we have a secondary 32bit length, strip it.
            if ($frameFlags['length'] == true){
                $data = substr($data, 4);
            }

            if ($frameFlags['unsync'] || $unsync) {
                $data = $this->unsynchroniseString($data);
            }
            
            // Special Decoding for specific tags
            switch (true) {
                case $tagName == 'APIC':
                    $tagData = $this->imageData($data);
                    break;

                case $tagName == "TXXX":
                    $tagEncoding = ord(substr($data, 0, 1));
                    $data = $this->trimNull(substr($data, 1));
                    $tagDataExtra = $this->trimNull(substr($data, 0, strpos($data, chr(0))));
                    $tagData = substr($data, strpos($data, chr(0)));
                    $tagData = $this->decodeText($tagData, $tagEncoding);
                    break;
                
                case $tagName == "COMM":
                    // Remove the text encoding byte
                    $tagEncoding = ord(substr($data, 0, 1));
                    $tagLang =  $this->trimNull(substr($data, 1, 3));
                    $tagData = $this->decodeText(substr($data, 4), $tagEncoding);
                    break;
                
                case substr($tagName, 0, 1) == 'T':
                case substr($tagName, 0, 1) == 'W':
                    $tagEncoding = ord(substr($data, 0, 1));
                    $tagData = $this->decodeText(substr($data, 1), $tagEncoding);
                    break;
                
                default:
                    $tagData = $data;
                    break;
            }
            
            $tags[] = [
                'tagName' => $tagName,
                'tagData' => $tagData,
                'tagLang' => $tagLang,
                'tagDataExtra' => $tagDataExtra
            ];
            
            $pointer += (10 + $tagDataSize);
            
        } while ($pointer < $length);
        return $tags;
    }
    
    public function decodeV4($length, $pointer, $unsync)
    {
        $tags = [];
        do {
            $tagEncoding = null;
            $tagDataExtra = null;
            $tagLang = null;
            
            fseek($this->fp, $pointer);
            
            // Read data for next tag
            $tagName = fread($this->fp, 4);
            $tagRawSize = fread($this->fp, 4);
            $tagStatusFlags = fread($this->fp, 1);
            $tagFormatFlags = fread($this->fp, 1);
            $tagDataSize = $this->unpackSyncSafeInteger($tagRawSize);
            
            // If we somehow end up with a dead resource (or stream past the end of the file etc.)
            if ($tagName === false) {
                return $tags;
            }
            
            // Have we hit the end padding?
            if (bin2hex($tagName) === '00000000') {
                return $tags;
            }

            $data = $tagDataSize ? fread($this->fp, $tagDataSize) : '';
            
            
            // Decode Flags
            $frameFlags = $this->version4Frame($tagStatusFlags, $tagFormatFlags);
            
            // If we have a secondary 32bit length, strip it.
            if ($frameFlags['length'] == true){
                $data = substr($data, 4);
            }
            
            if ($frameFlags['unsync'] || $unsync) {
                $data = $this->unsynchroniseString($data);
            }
            
            // Special Decoding for specific tags
            switch (true) {
                case $tagName == 'APIC':
                    $tagData = $this->imageData($data);
                    break;

                case $tagName == "TXXX":
                    $tagEncoding = ord(substr($data, 0, 1));
                    $data = $this->trimNull(substr($data, 1));
                    $tagDataExtra = $this->trimNull(substr($data, 0, strpos($data, chr(0))));
                    $tagData = substr($data, strpos($data, chr(0)));
                    $tagData = $this->decodeText($tagData, $tagEncoding);
                    break;
                
                case $tagName == "COMM":
                    // Remove the text encoding byte
                    $tagEncoding = ord(substr($data, 0, 1));
                    $tagLang =  $this->trimNull(substr($data, 1, 3));
                    $tagData = $this->decodeText(substr($data, 4), $tagEncoding);
                    break;
                
                case substr($tagName, 0, 1) == 'T':
                case substr($tagName, 0, 1) == 'W':
                    $tagEncoding = ord(substr($data, 0, 1));
                    $tagData = $this->decodeText(substr($data, 1), $tagEncoding);
                    break;
                
                default:
                    $tagData = $data;
                    break;
            }
            
            $tags[] = [
                'tagName' => $tagName,
                'tagData' => $tagData,
                'tagLang' => $tagLang,
                'tagDataExtra' => $tagDataExtra
            ];
            
            $pointer += (10 + $tagDataSize);
            
        } while ($pointer < $length);
        return $tags;
    }
    
    public function decodeText($data, $encoding)
    {
        if ($encoding == 1 or $encoding == 2) {
            $data = mb_convert_encoding($data, 'UTF-8' , 'UTF-16');
        }
        return $this->trimNull($data);
    }

    protected function imageData($data)
    {
        // [FIXME] Clean this when I have more brain power
        $position = 0;
        $image['text_encoding'] = ord(substr($data, 0, 1));
        $mime = substr($data, 1);
        $position = stripos($mime, null);
        $image['MIME'] = substr($mime, 0, $position);
        $image['picture_type'] = substr($mime, $position, 1);
        $position += 1; 
        $description = substr($mime, $position);
        $descPosition = stripos($description, null);
        $image['description'] = substr($description, $position, $descPosition);
        $image['image'] = $this->trimNull(substr($description, $descPosition));
        #echo "<img src='data:image/jpeg;base64,".base64_encode($image['image'])."'/>";
        return $image;

    }

    protected function unsynchroniseString($string)
    {
        return str_replace(chr(255) . chr(0), chr(255), $string);
    }

    protected function version3Frame($frameStatus, $frameFormat)
    {
        return [
            'tag' =>         bin2hex($frameStatus) & 0b10000000,
            'file' =>        bin2hex($frameStatus) & 0b01000000,
            'read-only' =>   bin2hex($frameStatus) & 0b00100000,
            
            'grouping' =>    bin2hex($frameFormat) & 0b00100000,
            'compression' => bin2hex($frameFormat) & 0b10000000,
            'encrypt' =>     bin2hex($frameFormat) & 0b01000000,
            'unsync' => false,
            'length' => false,
        ];
    }
    
    protected function version4Frame($frameStatus, $frameFormat)
    {
        return [
            'tag' =>         bin2hex($frameStatus) & 0b01000000,
            'file' =>        bin2hex($frameStatus) & 0b00100000,
            'read-only' =>   bin2hex($frameStatus) & 0b00010000,
            
            'grouping' =>    bin2hex($frameFormat) & 0b01000000,
            'compression' => bin2hex($frameFormat) & 0b00001000,
            'encrypt' =>     bin2hex($frameFormat) & 0b00000100,
            'unsync' =>      bin2hex($frameFormat) & 0b00000010,
            'length' =>      bin2hex($frameFormat) & 0b00000001,
        ];
    }
    
    public function trimNull($data)
    {
        return trim($data, chr(0));
    }
}
