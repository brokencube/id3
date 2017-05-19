<?php
namespace classes;

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
        $mainHeaderLength = $this->unpackSyncSafeInteger(substr($mainHeader, 6, 4));

        $versionNumber = ord(substr($mainHeader, 3, 1));
        $pointer = 10;
        
        $mainFlags = substr($mainHeader, 5, 1);
        if ($mainFlags & 0x80) {
            $unsync = true;
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
        do {
            fseek($this->fp, $pointer);
            
            // Read data for next tag
            $tagName = fread($this->fp, 3);
            $tagRawSize = fread($this->fp, 3);
            list(,$tagDataSize) = unpack('N', chr(0) . $tagRawSize);
            $data = fread($this->fp, $tagDataSize);
            
            // Have we hit the end padding?
            if (bin2hex($tagName) === '000000') {
                return $tags;
            }

            if ($frameFormat['unsync'] || $unsync) {
                $data = $this->stringReplace($data);
            }

            // Trim any nul chars off the data
            $data = $this->trimNull($data);
            
            // Do special things for the Com tag
            if ($tagName == "COM") {
                $tagData = trim(substr($data, 3), chr(0));
                $tagLang = substr($data, 0, 3);
            } else {
                $tagData = $data;
                $tagLang = null;
            }

            $tags[] = [
                'tagName' => $tagName,
                'tagData' => $tagData,
                'tagLang' => $tagLang,
            ];
            
            $pointer += (6 + $tagDataSize);
            
        } while ($pointer < $length);
        return $tags;
    }
        
    public function decodeV3($length, $pointer, $unsync)
    {
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
            $data = fread($this->fp, $tagDataSize);
            
            // Have we hit the end padding?
            if (bin2hex($tagName) === '00000000') {
                return $tags;
            }
            
            // Decode Flags
            $statusFormat = $this->version3FrameStatus($tagStatusFlags);
            $frameFormat = $this->version3FrameFormat($tagFormatFlags);
            
            // If we have a secondary 32bit length, strip it.
            if ($frameFormat['length'] == true){
                $data = substr($data, 4);
            }

            if ($frameFormat['unsync'] || $unsync) {
                $data = $this->stringReplace($data);
            }
            
            // Special Decoding for specific tags
            switch (true) {
                case $tagName == 'APIC':
                    $tagData = $this->imageData($data);
                    break;

                case $tagName == "TXXX":
                    $tagEncoding = ord(substr($data, 0, 1));
                    $data = $this->trimNulls(substr($data, 1));
                    $tagDataExtra = $this->trimNulls(substr($data, 0, strpos($data, chr(0))));
                    $tagData = substr($data, strpos($data, chr(0)));
                    $tagData = $this->decodeText($tagData, $textEncoding);
                    break;
                
                case $tagName == "COMM":
                    // Remove the text encoding byte
                    $tagEncoding = ord(substr($data, 0, 1));
                    $tagLang =  $this->trimNulls(substr($data, 1, 3));
                    $tagData = $this->decodeText(substr($data, 4), $textEncoding);
                    break;
                
                case substr($tagName, 0, 1) == 'T':
                case substr($tagName, 0, 1) == 'W':
                    $tagEncoding = ord(substr($data, 0, 1));
                    $tagData = $this->decodeText(substr($data, 1), $textEncoding);
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
            $data = fread($this->fp, $tagDataSize);
            
            // Have we hit the end padding?
            if (bin2hex($tagName) === '00000000') {
                return $tags;
            }
            
            // Decode Flags
            $statusFormat = $this->version4FrameStatus($tagStatusFlags);
            $frameFormat = $this->version4FrameFormat($tagFormatFlags);
            
            // If we have a secondary 32bit length, strip it.
            if ($frameFormat['length'] == true){
                $data = substr($data, 4);
            }
            
            if ($frameFormat['unsync'] || $unsync) {
                $data = $this->stringReplace($data);
            }
            
            // Special Decoding for specific tags
            switch (true) {
                case $tagName == 'APIC':
                    $tagData = $this->imageData($data);
                    break;

                case $tagName == "TXXX":
                    $tagEncoding = ord(substr($data, 0, 1));
                    $data = $this->trimNulls(substr($data, 1));
                    $tagDataExtra = $this->trimNulls(substr($data, 0, strpos($data, chr(0))));
                    $tagData = substr($data, strpos($data, chr(0)));
                    $tagData = $this->decodeText($tagData, $textEncoding);
                    break;
                
                case $tagName == "COMM":
                    // Remove the text encoding byte
                    $tagEncoding = ord(substr($data, 0, 1));
                    $tagLang =  $this->trimNulls(substr($data, 1, 3));
                    $tagData = $this->decodeText(substr($data, 4), $textEncoding);
                    break;
                
                case substr($tagName, 0, 1) == 'T':
                case substr($tagName, 0, 1) == 'W':
                    $tagEncoding = ord(substr($data, 0, 1));
                    $tagData = $this->decodeText(substr($data, 1), $textEncoding);
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
        return $this->trimNulls($data);
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
        echo "<img src='data:image/jpeg;base64,".base64_encode($image['image'])."'/>";
        return $image;

    }

    protected function stringReplace($string)
    {
        return str_replace(chr(255) . chr(0), chr(255), $string);
    }

    protected function version3FrameFormat($frameFormat)
    {
        
        if (bin2hex($frameFormat) & 0x01) {
            $frameArr['compression'] = true;               
        }
        // if Unsynchronisation is set then remove the next 4 bytes    
        if (bin2hex($frameFormat) & 0x02) {
            $frameArr['encrypt'] = true;
        }

        if (bin2hex($frameFormat) & 0x04) {
            $frameArr['grouping'] = true;
        }

        return $frameArr;

    }
    
    protected function version3FrameStatus($frameFormat)
    {
        
        if (bin2hex($frameFormat) & 0x01) {
            $frameArr['tag'] = true;               
        }
        // if Unsynchronisation is set then remove the next 4 bytes    
        if (bin2hex($frameFormat) & 0x02) {
            $frameArr['file'] = true;
        }

        if (bin2hex($frameFormat) & 0x04) {
            $frameArr['read-only'] = true;
        }

        return $frameArr;

    }

    protected function version4FrameFormat($frameFormat)
    {
        
        if (bin2hex($frameFormat) & 0x01) {
            $frameArr['length'] = true;               
        }
        // if Unsynchronisation is set then remove the next 4 bytes    
        if (bin2hex($frameFormat) & 0x02) {
            $frameArr['unsync'] = true;
        }

        if (bin2hex($frameFormat) & 0x04) {
            $frameArr['encrypt'] = true;
        }

        if (bin2hex($frameFormat) & 0x08) {
            $frameArr['compression'] = true;
        }

        if (bin2hex($frameFormat) & 0x64) {
            $frameArr['grouping'] = true;
        }

        return $frameArr;

    }

    protected function version4FrameStatus($frameFormat)
    {
        
        if (bin2hex($frameFormat) & 0x01) {
            $frameArr['tag'] = true;               
        }
        // if Unsynchronisation is set then remove the next 4 bytes    
        if (bin2hex($frameFormat) & 0x02) {
            $frameArr['file'] = true;
        }

        if (bin2hex($frameFormat) & 0x04) {
            $frameArr['read-only'] = true;
        }

        return $frameArr;
    }
    
    public function trimNulls($data)
    {
        return trim($data, chr(0));
    }
}