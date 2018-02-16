<?php
namespace brokencube\ID3;

class Writer
{
    /*
        http://id3.org/id3v2.3.0
    */
    
    protected $filename;
    protected $data;
    public function __construct($filename, $data = [])
    {
        $this->filename = $filename;
        $this->data = $data;
    }
    
    public function addTag($tagname, $data, $lang = null, $extended = null)
    {
        $this->data[] = [
            'title' => $tagname,
            'desc' => $data,
            'lang' => $lang,
            'tag' => $extended
        ];
    }

    public function create()
    {
        $data = '';
        // Loop through the data and create the individual headers then merge them all together
        foreach($this->data as $headerList) {
            $data .= $this->packTextTag($headerList['title'], $headerList['desc'], $headerList['lang'], $headerList['tag']);
        }

        // This is to put padding at the end of the file
        $data .= pack('a255', ''); // 255 bytes of padding

        // Add the ID3 tag to the front of the header
        $id3 = $this->topHeader(strlen($data)) . $data;

        return $id3 . file_get_contents($this->filename);
    }

    protected function packTextTag($tagname, $text, $lang = null, $extendedTagName = null)
    {
        // this is the data that goes into the tag
        // add in language tags at the begining and user defined tags at the end
        list($headerName, $extendedTagName2, $tagtype) = $this->tagName($tagname);
        $extendedTagName = $extendedTagName ?: $extendedTagName2;
        
        switch ($tagtype) {
            case 'lang':
                $lang = ($lang ?: 'xxx') . chr(0);
                $extendedTagName = $extendedTagName ? "\xFF\xFE" . mb_convert_encoding($extendedTagName, "UTF-16LE", "UTF-8") . chr(0) . chr(0) : '';
                $text = "\xFF\xFE" . mb_convert_encoding($text, "UTF-16LE", "UTF-8");
                $text = chr(1) . $lang . $extendedTagName . $text;
                
            case 'txt':
                $extendedTagName = $extendedTagName ? "\xFF\xFE" . mb_convert_encoding($extendedTagName, "UTF-16LE", "UTF-8") . chr(0) . chr(0) : '';
                $text = "\xFF\xFE" . mb_convert_encoding($text, "UTF-16LE", "UTF-8");
                $text = chr(1) . $extendedTagName . $text;
                break;
            case 'bin':
                $text = $text;
                break;
            case 'num':
                $text = chr(0) . $text;
            case 'url':
                $text = $text;
                break;  
        }
        
        $header = [
            ['N', $headerName],                 // Name of the header
            ['N', strlen($text)],               // Size of data
            ['n', 0x00],                        // Flags
        ];
        
        return $this->packFields($header) . $text;
    }

    protected function generateTotalSizePacked($size)
    {
        return $this->packFields([
            ['C', ($size >> 21) & 0b01111111],
            ['C', ($size >> 14) & 0b01111111],
            ['C', ($size >> 7)  & 0b01111111],
            ['C',  $size        & 0b01111111],
        ]);
    }


    protected function topHeader($size)
    {
        $header = [
            ['C', 0x03],                    // 4 ID3v2 minor version (3)
            ['C', 0x00],                    // 1 Minor version 0 (NUL)
            ['C', 0x00],                    // 1 Flags
        ];

        return 'ID3' . $this->packFields($header) . $this->generateTotalSizePacked($size);
    }

    protected function tagName($title)
    {
        $title = strtoupper($title);
        
        // By default all the tiles have the TXXX code
        $validtags = [
            'AENC' => 'bin', //    [[#sec4.20|Audio encryption]]
            'APIC' => 'bin', //    [#sec4.15 Attached picture]
            'COMM' => 'lang', //    [#sec4.11 Comments]
            'COMR' => 'bin', //    [#sec4.25 Commercial frame]
            'ENCR' => 'bin', //    [#sec4.26 Encryption method registration]
            'EQUA' => 'bin', //    [#sec4.13 Equalization]
            'ETCO' => 'bin', //    [#sec4.6 Event timing codes]
            'GEOB' => 'bin', //    [#sec4.16 General encapsulated object]
            'GRID' => 'bin', //    [#sec4.27 Group identification registration]
            'IPLS' => 'bin', //    [#sec4.4 Involved people list]
            'LINK' => 'bin', //    [#sec4.21 Linked information]
            'MCDI' => 'bin', //    [#sec4.5 Music CD identifier]
            'MLLT' => 'bin', //    [#sec4.7 MPEG location lookup table]
            'OWNE' => 'bin', //    [#sec4.24 Ownership frame]
            'PRIV' => 'bin', //    [#sec4.28 Private frame]
            'PCNT' => 'bin', //    [#sec4.17 Play counter]
            'POPM' => 'bin', //    [#sec4.18 Popularimeter]
            'POSS' => 'bin', //    [#sec4.22 Position synchronisation frame]
            'RBUF' => 'bin', //    [#sec4.19 Recommended buffer size]
            'RVAD' => 'bin', //    [#sec4.12 Relative volume adjustment]
            'RVRB' => 'bin', //    [#sec4.14 Reverb]
            'SYLT' => 'lang', //    [#sec4.10 Synchronized lyric/text]
            'SYTC' => 'bin', //    [#sec4.8 Synchronized tempo codes]
            'TALB' => 'txt', //    [#TALB Album/Movie/Show title]
            'TBPM' => 'num', //    [#TBPM BPM (beats per minute)]
            'TCOM' => 'txt', //    [#TCOM Composer]
            'TCON' => 'txt', //    [#TCON Content type]
            'TCOP' => 'txt', //    [#TCOP Copyright message]
            'TDAT' => 'num', //    [#TDAT Date]
            'TDLY' => 'num', //    [#TDLY Playlist delay]
            'TENC' => 'txt', //    [#TENC Encoded by]
            'TEXT' => 'txt', //    [#TEXT Lyricist/Text writer]
            'TFLT' => 'txt', //    [#TFLT File type]
            'TIME' => 'num', //    [#TIME Time]
            'TIT1' => 'txt', //    [#TIT1 Content group description]
            'TIT2' => 'txt', //    [#TIT2 Title/songname/content description]
            'TIT3' => 'txt', //    [#TIT3 Subtitle/Description refinement]
            'TKEY' => 'txt', //    [#TKEY Initial key]
            'TLAN' => 'txt', //    [#TLAN Language(s)]
            'TLEN' => 'num', //    [#TLEN Length]
            'TMED' => 'txt', //    [#TMED Media type]
            'TOAL' => 'txt', //    [#TOAL Original album/movie/show title]
            'TOFN' => 'txt', //    [#TOFN Original filename]
            'TOLY' => 'txt', //    [#TOLY Original lyricist(s)/text writer(s)]
            'TOPE' => 'txt', //    [#TOPE Original artist(s)/performer(s)]
            'TORY' => 'txt', //    [#TORY Original release year]
            'TOWN' => 'txt', //    [#TOWN File owner/licensee]
            'TPE1' => 'txt', //    [#TPE1 Lead performer(s)/Soloist(s)]
            'TPE2' => 'txt', //    [#TPE2 Band/orchestra/accompaniment]
            'TPE3' => 'txt', //    [#TPE3 Conductor/performer refinement]
            'TPE4' => 'txt', //    [#TPE4 Interpreted, remixed, or otherwise modified by]
            'TPOS' => 'num', //    [#TPOS Part of a set]
            'TPUB' => 'txt', //    [#TPUB Publisher]
            'TRCK' => 'num', //    [#TRCK Track number/Position in set]
            'TRDA' => 'txt', //    [#TRDA Recording dates]
            'TRSN' => 'txt', //    [#TRSN Internet radio station name]
            'TRSO' => 'txt', //    [#TRSO Internet radio station owner]
            'TSIZ' => 'num', //    [#TSIZ Size]
            'TSRC' => 'txt', //    [#TSRC ISRC (international standard recording code)]
            'TSSE' => 'txt', //    [#TSEE Software/Hardware and settings used for encoding]
            'TYER' => 'num', //    [#TYER Year]
            'TXXX' => 'txt', //    [#TXXX User defined text information frame]
            'UFID' => 'bin', //    [#sec4.1 Unique file identifier]
            'USER' => 'lang', //    [#sec4.23 Terms of use]
            'USLT' => 'lang', //    [#sec4.9 Unsychronized lyric/text transcription]
            'WCOM' => 'url', //    [#WCOM Commercial information]
            'WCOP' => 'url', //    [#WCOP Copyright/Legal information]
            'WOAF' => 'url', //    [#WOAF Official audio file webpage]
            'WOAR' => 'url', //    [#WOAR Official artist/performer webpage]
            'WOAS' => 'url', //    [#WOAS Official audio source webpage]
            'WORS' => 'url', //    [#WORS Official internet radio station homepage]
            'WPAY' => 'url', //    [#WPAY Payment]
            'WPUB' => 'url', //    [#WPUB Publishers official webpage]
            'WXXX' => 'url', //    [#WXXX User defined URL link frame]
            'GRP1' => 'txt', // ITunes Grouping
            'TCMP' => 'txt', // ITunes compilation field
        ];
        
        if (array_key_exists($title, $validtags)) {
            list(,$value) = unpack('N', $title);
            return [$value, null, $validtags[$title]];
        } else {
            list(,$value) = unpack('N', 'TXXX');
            return [$value, $title, 'txt'];
        }
    }

    /**
     * Create a format string and argument list for pack(), then call
     * pack() and return the result.
     * 
     * @param array $fields
     * @return string
     */
    protected function packFields($fields) {
        $fmt = '';
        $args = [];
        
        // populate format string and argument list
        foreach ($fields as $field) {
            $fmt .= $field[0];
            $args[] = $field[1];
        }
        
        // prepend format string to argument list
        array_unshift($args, $fmt);
        
        // build output string from header and compressed data
        return call_user_func_array('pack', $args);
    }
}
