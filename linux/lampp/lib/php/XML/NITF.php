<?php
/* vim: set expandtab tabstop=4 softtabstop=4 shiftwidth=4: */
// +----------------------------------------------------------------------+
// | PHP version 4                                                        |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003-2004 TownNews.com                                 |
// +----------------------------------------------------------------------+
// | This source file is subject to version 2.0 of the PHP license,       |
// | that is bundled with this package in the file LICENSE, and is        |
// | available at through the world-wide-web at                           |
// | http://www.php.net/license/2_02.txt.                                 |
// | If you did not receive a copy of the PHP license and are unable to   |
// | obtain it through the world-wide-web, please send a note to          |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Authors: Patrick O'Lone <polone@townnews.com>                        |
// +----------------------------------------------------------------------+
//
// NITF.php,v 1.4 2005/01/17 23:46:32 polone Exp

require_once('XML/Parser.php');

/**
* Generic NITF Parser class
*
* This class provides basic NITF parsing. Many of the major elements of the NITF
* standard are supported. This implementation is based off the NITF 3.1 DTD,
* publicly available at the following URL:
*
* http://www.nitf.org/site/nitf-documentation/nitf-3-1.dtd
*
* Note that not all elements of this standard are not supported.
*
* @author Patrick O'Lone <polone@townnews.com>
* @version 1.4
* @package XML
*/
class XML_NITF extends XML_Parser
{
    /**
    * @var array
    * Document metadata. Container for metadata information about this
    * particular document.
    * @see getDocData()
    * @access private
    */
    var $m_kDocData = array('key-list' => array());
    
    /**
    * @var array
    * Information about specific instance of an item's publication. Contains
    * metadata about how the particular news object was used in a specific
    * instance.
    * @see getPubData()
    * @access private
    */
    var $m_kPubData = array();
    
    /**
    * @var array
    * Information about the creative history of the document; also used as an
    * audit trail. Includes who made changes, when the changes were made, and
    * why. Each element of the array is a key-based array that corresponds to
    * the <revision-history> element.
    * @see getRevision()
    * @access private
    */
    var $m_akRevisions = array();
    
    /**
    * @var array
    * The various headlines that were found in the document. The headlines are
    * keyed by the levels of HLX. The default hedline (if no level is found) is
    * HL1.
    * @see getHedlines()
    * @access private
    */
    var $m_kHedlines = array( 'HL1' => null, 'HL2' => array() );

    /**
    * @var string
    * Story abstract summary or synopsis of the contents of the document.
    * @access private
    */
    var $m_sAbstract = null;
    
    /**
    * @var string
    * Significant place mentioned in an article. Used to normalize locations.
    * The location in this variable is the place where the story's events will
    * or have unfolded.
    * @access private
    */
    var $m_sLocation = null;
    
    /**
    * @var string
    * Information distributor. May or may not be the owner or creator.
    * @access private
    */
    var $m_sDistributor = null;

    /**
    * @var string
    * The elements of the byline, including the author's name and title.
    * @see getByline()
    * @access private
    */
    var $m_kByline = array( 'author' => null, 'title' => null );
    
    /**
    * @var array
    * An array of paragraphs extracted from the document
    * @see getLede(), getContent()
    * @access private
    */
    var $m_aContent = array();

    /**
    * @var array
    * A list of media reference elements as found in the body section of the
    * document. Each element is an array itself with keyed properties related
    * to media element in question.
    * @see getMedia()
    * @access private
    */
    var $m_aMedia = array();

    /**
    * @var array
    * A list of tags that were parsed (in order) denoting the current sequence
    * of tags that were parsed. This is array is used for parsing the document
    * elements in a particular order (if needed).
    * @see StartHandler(), EndHandler(), cdataHandler()
    * @access private
    */
    var $m_aParentTags = array();
    
    /**
    * @var string
    * A byline at the end of a story. Example: Stuart Myles contributed to this
    * article.
    * @see getTagline()
    * @access private
    */
    var $m_sTagline = null;
    
    /**
    * @var string
    * Free-form bibliographic data. Used to elaborate on the source of
    * information.
    * @see getBibliography()
    * @access private
    */
    var $m_sBibliography = null;
    
    /**
    * Access all or specific elements of the <docdata> block
    *
    * @return mixed
    * All of the elements from the <docdata> block will be returned if a specific
    * property is not provided. If a specific property is requested and is found
    * in the docdata block, then that property will be returned. If the property
    * cannot be found, null is returned.
    *
    * @param string
    * The property of the <docdata> block to return, the most common being:
    *
    * "doc-id" - a unique identifier of this document (string)
    * "key-list" - a list of keywords provided with the document (array)
    * "copyright" - the copyright holder (string)
    * "series" - if the document is part of series (string)
    * "urgency" - a number between 1 (urgent) and 8 (not urgent) (integer)
    * "date.issue" - date the document was issued (UNIX timestamp)
    * "date.release" - date the document is publicly available (UNIX timestamp)
    * "date.expires" - date the document is no longer valid (UNIX timestamp)
    *
    * @see getDocDataElement()
    * @access public
    */
    function getDocData( $sProperty = null )
    {
        if (!empty($sProperty)) {

            $sProperty = strtolower($sProperty);
            if (isset($this->m_kDocData[$sProperty])) {

                return $this->m_kDocData[$sProperty];

            }
            return null;
        
        }
        return $this->m_kDocData;
    }

    /**
    * Returns all elements or a specific element from the <pubdata> block
    *
    * @return mixed
    * Returns string, numeric, or array values depending on the property being
    * accessed from the <pubdata> block.
    *
    * @access public
    */
    function getPubData( $sProperty = null )
    {
        if (!empty($sProperty)) {

            $sProperty = strtolower($sProperty);
            if (isset($this->m_kPubData[$sProperty])) {

                return $this->m_kPubData[$sProperty];

            }
            return null;

        }
        
        return $this->m_kPubData;
    }
    
    /**
    * Get the revision history
    *
    * @return array
    * An array containing key-value arrays. The properties of each array element
    * in this array are:
    *
    * "comment" - Reason for the revision
    * "function" - Job function of individual performing revision
    * "name" - Name of the person who made the revision
    * "norm" - Date of the revision
    *
    * @access public
    */
    function getRevision()
    {
        return $this->m_akRevisions;
    }

    /**
    * Retrieve all headlines or a single headline denoted by key
    *
    * @return mixed
    * Returns an array if no specific headline element is requested, or a string
    * if the specific headline element requested exists
    *
    * @param string
    * The key value corresponding to the headline to be retrieved
    *
    * @access public
    */
    function getHeadline( $nLevel = 1 )
    {
        return $this->m_kHedlines["HL$nLevel"];
    }

    /**
    * Return information about the author of a document
    *
    * @param string
    * The field of the byline to retrieve.
    *
    * @access public
    */
    function getByline( $sProperty = 'author' )
    {
        $sProperty = strtolower($sProperty);
        if (isset($this->m_kByline[$sProperty])) {

            return $this->m_kByline[$sProperty];

        }
        
        return null;
    }
    
    /**
    * Query for a list of related media elements
    *
    * @return array
    * Returns an array of all media reference data, or an array of select media
    * reference data determined by the property parameter passed.
    *
    * @param string
    * If supplied, only this property will be returned for each element of the
    * media reference array.
    *
    * @access public
    */
    function getMedia( $sProperty = null )
    {
        if (empty($sProperty)) {

           return $this->m_aMedia;

        } else {

           $aMediaRefs = array();
           foreach($this->m_aMedia as $aMediaElem) {

              if (isset($aMediaElem[$sProperty])) {

                 array_push($aMediaRefs, $aMediaElem[$sProperty]);

              }
           }
           
           return $aMediaRefs;

        }
    }
    
    /**
    * Returns the lede (sometimes called lead) paragraph
    *
    * @return string
    * Returns the lede paragraph if it is defined, or null otherwise
    *
    * @access public
    */
    function getLede()
    {
        if (isset($this->m_aContent[0])) {

           return $this->m_aContent[0];

        }
        return null;
    }
    
    /**
    * Returns the paragraphs of content
    *
    * @return array
    * An array of elements that represent a single paragraph each
    *
    * @access public
    */
    function &getContent()
    {
        return $this->m_aContent;
    }
    
    /**
    * Returns the tag line (if one exists)
    *
    * @return string
    * The tag line extracted from the NITF data source
    *
    * @access public
    */
    function getTagline()
    {
        return $this->m_sTagline;
    }
    
    /**
    * Returns the free-form bibliographic data
    *
    * @return string
    * The bibliography (if one exists) is returned
    *
    * @access public
    */
    function getBibliography()
    {
        return $this->m_sBibliography;
    }
    
    /**
    * Get a string version of the article
    *
    * @return string
    * A string representing the main headline, author, content, and tagline.
    *
    * @param string
    * The character(s) used to separate each article element in the string that
    * is returned - often referred to as the CRLF.
    *
    * @access public
    */
    function &toString( $sCRLF = "\n" )
    {
        $sArticle = "{$this->m_kHedlines['HL1']}$sCRLF";

        if (!empty($this->m_kByline['author'])) {

            $sArticle .= "{$this->m_kByline['author']}$sCRLF";

        }
        
        if (!empty($this->m_sLocation)) {

           $sArticle .= "{$this->m_sLocation} - ";

        }
        
        $sArticle .= join($sCRLF, $this->m_aContent);

        if (!empty($this->m_sTagline)) {

            $sArticle .= "$sCRLF{$this->m_sTagline}";
            
        }
        
        return $sArticle;
    }

    /**
    * Handle start XML elements and attributes
    *
    * When a new element is begun, this handler is executed.
    *
    * @param object
    * The XML parser object instance that was inherited from the XML_Parser
    * class
    *
    * @param string
    * A tag element from the XML data stream
    *
    * @param array
    * An array of XML attributes associated with the given tag supplied
    *
    * @access private
    */
    function StartHandler($oParser, $sName, $kAttrib )
    {
        // Push the element into the stack of XML elements already visited
        
        array_push($this->m_aParentTags, $sName);
        
        // Handle the attributes of the XML tags
        
        switch ($sName) {

           case 'HL2':
              $this->_sHedline = null;
              break;

           case 'P':
              if (!empty($kAttrib['LEDE']) && ($kAttrib['LEDE'] == 'true')) {

                  $this->_bIsLede = true;

              }
              $this->_sContent = null;
              break;

           case 'DOC.COPYRIGHT':
              $this->m_sCopyright = $kAttrib['HOLDER'];
              break;
              
           case 'MEDIA':
              $this->_kMedia = array();
              if (!empty($kAttrib['MEDIA-TYPE'])) {

                  $this->_kMedia['type'] = $kAttrib['MEDIA-TYPE'];

              } else {

                  $this->_kMedia['type'] = 'other';

              }
              
              $this->_kMedia['source'] = null;
              $this->_kMedia['mime-type'] = null;
              $this->_kMedia['caption'] = null;
              $this->_kMedia['data'] = null;
              $this->_kMedia['encoding'] = null;
              $this->_kMedia['producer'] = null;
              $this->_kMedia['meta'] = array();
              break;
              
           case 'MEDIA-REFERENCE':
              if (!empty($kAttrib['SOURCE'])) {

                  $this->_kMedia['source'] = $kAttrib['SOURCE'];

              // Compatibility with the AP Usenet feed - note that this is a non
              // standard attribute and is NOT a part of NITF standards

              } elseif (!empty($kAttrib['DATA-LOCATION'])) {

                  $this->_kMedia['source'] = $kAttrib['DATA-LOCATION'];

              }
              
              $this->_kMedia['mime-type'] = $kAttrib['MIME-TYPE'];
              break;
              
           case 'MEDIA-OBJECT':
              $this->_kMedia['encoding'] = $kAttrib['ENCODING'];
              break;
              
           case 'MEDIA-METADATA':
              if (!empty($kAttrib['NAME'])) {

                 $this->_kMedia[$kAttrib['NAME']] = $kAttrib['VALUE'];

              }
              break;

           case 'PUBDATA':
              foreach ($kAttrib as $sKey => $sValue) {
                  
                  $this->m_kPubData[strtolower($sKey)] = $sValue;

              }
              break;

           case 'DOC-ID':
              $this->m_kDocData['doc-id'] = $kAttrib['ID-STRING'];
              break;
			  
			// NITF 3.0 extension - added per request by Lars Schenk
			// (info@lars-schenk.de). Document urgency status information.
			
			case 'URGENCY':
				$this->m_kDocData['urgency'] = $kAttrib['ED-URG'];
				break; 

           // The list of keywords or phrases are just added to the array of
           // keywords.

           case 'KEYWORD':
              if (empty($this->m_kDocData['key-list'])) {

                  $this->m_kDocData['key-list'] = array();

              }

              array_push($this->m_kDocData['key-list'], $kAttrib['KEY']);
              break;

           // The release, expiration, and issuing dates of this article. The
           // ISO-8601 time stamp settings are preserved, but you can use the
           // magic function strtotime() to convert these to time stamp values.

           case 'DATE.RELEASE':
           case 'DATE.EXPIRE':
           case 'DATE.ISSUE':
              if (!empty($kAttrib['NORM'])) {

                  $sName = strtolower($sName);
                  $this->m_kDocData[$sName] = $kAttrib['NORM'];
                  
              }
              break;
              
           case 'REVISION-HISTORY':
              array_push($this->m_akRevisions, array_change_key_case($kAttrib, CASE_LOWER));
              break;
              
        }

    }

    /**
    * Called when a tag element ends
    *
    * @param object
    * The parser object parsing the XML data
    *
    * @param string
    * The name of the tag element that has just ended
    *
    * @access private
    */
    function EndHandler( $oParser, $sName )
    {
        switch ( $sName ) {

           case 'HL1':
              $this->m_kHedlines['HL1'] = trim($this->m_kHedlines['HL1']);
              break;

           case 'HL2':
              array_push($this->m_kHedlines['HL2'], trim($this->_sHedline));
              unset($this->_sHedline);
              break;

           case 'P':
              if (isset($this->_bIsLede)) {

                  array_unshift($this->m_aContent, trim($this->_sContent));
                  unset($this->_bIsLede);
                  
              } else {

                  array_push($this->m_aContent, trim($this->_sContent));
                  
              }
              unset($this->_sContent);
              break;
              
           case 'MEDIA':
              array_push($this->m_aMedia, $this->_kMedia);
              unset($this->_kMedia);
              break;
              
        }
        
        array_pop($this->m_aParentTags);
    }

    /**
    * Handles CDATA sections from the XML document during processing
    *
    * @param object
    * The XML parser instance inherited from the XML_Parser class
    *
    * @param string
    * The data chunk to be processed from the parser
    *
    * @access private
    */
    function cdataHandler( $oParser, $sData )
    {
        if (!in_array('MEDIA-OBJECT', $this->m_aParentTags)) {

            $sData = preg_replace('#\s+#', ' ', $sData);
            
        }
                
        // Elements that can be found in the BODY.HEAD section of the NITF
        // document are defined in this handler.
        
        if (in_array('BODY.HEAD', $this->m_aParentTags)) {

           // We don't care if they use other attribute items, we just want the
           // textual version of the byline. Other attributes are appended to
           // the byline data.

           if (in_array('BYLINE', $this->m_aParentTags)) {

              if (in_array('BYTTL', $this->m_aParentTags)) {

                 $this->m_kByline['title'] .= $sData;
                 return;
                 
              }
              
              $this->m_kByline['author'] .= $sData;
              return;

           }

           // Generally, the distributor is the same as the company supplying
           // the content. However, this is not always the case (the AP, for
           // example).

           if (in_array('DISTRIBUTOR', $this->m_aParentTags)) {

               $this->m_sDistributor .= $sData;
               return;

           }
           
           // The location where the story pertains too.

           if (in_array('DATELINE', $this->m_aParentTags)) {

               if (in_array('LOCATION', $this->m_aParentTags)) {

                   $this->m_sLocation .= $sData;

               }
               return;
           }
           
           // There are only two possibilities for hedlines, the main headline
           // or a subheadline.

           if (in_array('HEDLINE', $this->m_aParentTags)) {

               if (in_array('HL2', $this->m_aParentTags)) {

                  $this->_sHedline .= $sData;
                  
               } else {

                   $this->m_kHedlines['HL1'] .= $sData;

               }

           }
           return;

        }

        // The article content, including the lead and following paragraphs, can
        // be found in this section of the XML document.

        if (in_array('BODY.CONTENT', $this->m_aParentTags)) {

            if (in_array('MEDIA', $this->m_aParentTags)) {

                // The media caption for the currently selected media element.

                if (in_array('MEDIA-CAPTION', $this->m_aParentTags)) {

                    $this->_kMedia['caption'] .= $sData;
                    return;

                }

                if (in_array('MEDIA-OBJECT', $this->m_aParentTags)) {

                    $this->_kMedia['data'] .= $sData;
                    return;

                }

            }
            
            // A paragraph element was found.

            if (in_array('P', $this->m_aParentTags)) {
                
                $this->_sContent .= $sData;
                return;
                
            }

        }
        
        // The <body.end> tag has two primary elements, <taglines> and the free
        // form <bibliography> tags.
        
        if (in_array('BODY.END', $this->m_aParentTags)) {

            if (in_array('TAGLINE', $this->m_aParentTags)) {

               $this->m_sTagline .= $sData;
               return;
               
            }
            
            if (in_array('BIBLIOGRAPHY', $this->m_aParentTags)) {


               $this->m_sBibliography .= $sData;

            }

        }

    }

}

?>
