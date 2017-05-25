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
        list($headerName, $extendedTagName2) = $this->tagName($tagname);
        $extendedTagName = $extendedTagName ?: $extendedTagName2;
        
        $text = chr(0) . ($lang ? $lang . chr(0) : '') . ($extendedTagName ? $extendedTagName . chr(0) : '') . $text . chr(0);
        
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
        // By default all the tiles have the TXXX code
        $validtags = [
            'AENC', //    [[#sec4.20|Audio encryption]]
            'APIC', //    [#sec4.15 Attached picture]
            'COMM', //    [#sec4.11 Comments]
            'COMR', //    [#sec4.25 Commercial frame]
            'ENCR', //    [#sec4.26 Encryption method registration]
            'EQUA', //    [#sec4.13 Equalization]
            'ETCO', //    [#sec4.6 Event timing codes]
            'GEOB', //    [#sec4.16 General encapsulated object]
            'GRID', //    [#sec4.27 Group identification registration]
            'IPLS', //    [#sec4.4 Involved people list]
            'LINK', //    [#sec4.21 Linked information]
            'MCDI', //    [#sec4.5 Music CD identifier]
            'MLLT', //    [#sec4.7 MPEG location lookup table]
            'OWNE', //    [#sec4.24 Ownership frame]
            'PRIV', //    [#sec4.28 Private frame]
            'PCNT', //    [#sec4.17 Play counter]
            'POPM', //    [#sec4.18 Popularimeter]
            'POSS', //    [#sec4.22 Position synchronisation frame]
            'RBUF', //    [#sec4.19 Recommended buffer size]
            'RVAD', //    [#sec4.12 Relative volume adjustment]
            'RVRB', //    [#sec4.14 Reverb]
            'SYLT', //    [#sec4.10 Synchronized lyric/text]
            'SYTC', //    [#sec4.8 Synchronized tempo codes]
            'TALB', //    [#TALB Album/Movie/Show title]
            'TBPM', //    [#TBPM BPM (beats per minute)]
            'TCOM', //    [#TCOM Composer]
            'TCON', //    [#TCON Content type]
            'TCOP', //    [#TCOP Copyright message]
            'TDAT', //    [#TDAT Date]
            'TDLY', //    [#TDLY Playlist delay]
            'TENC', //    [#TENC Encoded by]
            'TEXT', //    [#TEXT Lyricist/Text writer]
            'TFLT', //    [#TFLT File type]
            'TIME', //    [#TIME Time]
            'TIT1', //    [#TIT1 Content group description]
            'TIT2', //    [#TIT2 Title/songname/content description]
            'TIT3', //    [#TIT3 Subtitle/Description refinement]
            'TKEY', //    [#TKEY Initial key]
            'TLAN', //    [#TLAN Language(s)]
            'TLEN', //    [#TLEN Length]
            'TMED', //    [#TMED Media type]
            'TOAL', //    [#TOAL Original album/movie/show title]
            'TOFN', //    [#TOFN Original filename]
            'TOLY', //    [#TOLY Original lyricist(s)/text writer(s)]
            'TOPE', //    [#TOPE Original artist(s)/performer(s)]
            'TORY', //    [#TORY Original release year]
            'TOWN', //    [#TOWN File owner/licensee]
            'TPE1', //    [#TPE1 Lead performer(s)/Soloist(s)]
            'TPE2', //    [#TPE2 Band/orchestra/accompaniment]
            'TPE3', //    [#TPE3 Conductor/performer refinement]
            'TPE4', //    [#TPE4 Interpreted, remixed, or otherwise modified by]
            'TPOS', //    [#TPOS Part of a set]
            'TPUB', //    [#TPUB Publisher]
            'TRCK', //    [#TRCK Track number/Position in set]
            'TRDA', //    [#TRDA Recording dates]
            'TRSN', //    [#TRSN Internet radio station name]
            'TRSO', //    [#TRSO Internet radio station owner]
            'TSIZ', //    [#TSIZ Size]
            'TSRC', //    [#TSRC ISRC (international standard recording code)]
            'TSSE', //    [#TSEE Software/Hardware and settings used for encoding]
            'TYER', //    [#TYER Year]
            'TXXX', //    [#TXXX User defined text information frame]
            'UFID', //    [#sec4.1 Unique file identifier]
            'USER', //    [#sec4.23 Terms of use]
            'USLT', //    [#sec4.9 Unsychronized lyric/text transcription]
            'WCOM', //    [#WCOM Commercial information]
            'WCOP', //    [#WCOP Copyright/Legal information]
            'WOAF', //    [#WOAF Official audio file webpage]
            'WOAR', //    [#WOAR Official artist/performer webpage]
            'WOAS', //    [#WOAS Official audio source webpage]
            'WORS', //    [#WORS Official internet radio station homepage]
            'WPAY', //    [#WPAY Payment]
            'WPUB', //    [#WPUB Publishers official webpage]
            'WXXX', //    [#WXXX User defined URL link frame]
    
            'GRP1', // ITunes Grouping
            'TCMP', // ITunes compilation field
        ];
        
        if (in_array($title, $validtags)) {
            list(,$value) = unpack('N', $title);
            return [$value, null];
        } else {
            list(,$value) = unpack('N', 'TXXX');
            return [$value, $title];
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
