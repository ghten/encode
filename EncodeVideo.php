<?php

/**
    * Parameters to entry in file encode`php in url with get
    * @param int       id - id of video
    * @param string    input - input url of video
    * @param string    output - output url of video
    * @param Boolean   drm - true/false if you want drm, put true
    */

$id=$_GET['id'];
$inputName = $_GET['input'];
$outputName = $_GET['output'];
$drm = $_GET['drm'];

use Bitmovin\api\ApiClient;

use Bitmovin\api\enum\AclPermission;
use Bitmovin\api\enum\CloudRegion;
use Bitmovin\api\enum\codecConfigurations\H264Profile;
use Bitmovin\api\enum\codecConfigurations\H265Profile;
use Bitmovin\api\enum\manifests\hls\MediaInfoType;
use Bitmovin\api\enum\manifests\dash\DashMuxingType;
use Bitmovin\api\enum\SelectionMode;
use Bitmovin\api\enum\Status;

use Bitmovin\api\exceptions\BitmovinException;

use Bitmovin\api\model\codecConfigurations\AACAudioCodecConfiguration;
use Bitmovin\api\model\codecConfigurations\H264VideoCodecConfiguration;
use Bitmovin\api\model\codecConfigurations\H265VideoCodecConfiguration;

use Bitmovin\api\model\encodings\drms\CencDrm;
use Bitmovin\api\model\encodings\drms\cencSystems\CencPlayReady;
use Bitmovin\api\model\encodings\drms\cencSystems\CencWidevine;

use Bitmovin\api\model\encodings\Encoding;
use Bitmovin\api\model\encodings\helper\Acl;
use Bitmovin\api\model\encodings\helper\EncodingOutput;
use Bitmovin\api\model\encodings\helper\InputStream;
use Bitmovin\api\model\encodings\muxing\FMP4Muxing;
use Bitmovin\api\model\encodings\muxing\TSMuxing;
use Bitmovin\api\model\encodings\muxing\MuxingStream;
use Bitmovin\api\model\encodings\streams\Stream;
use Bitmovin\api\model\inputs\HttpsInput;
use Bitmovin\api\model\outputs\Output;
use Bitmovin\api\model\outputs\GcsOutput;

use Bitmovin\api\model\manifests\dash\AudioAdaptationSet;
use Bitmovin\api\model\manifests\dash\DashDrmRepresentation;
use Bitmovin\api\model\manifests\dash\DashManifest;
use Bitmovin\api\model\manifests\dash\ContentProtection;
use Bitmovin\api\model\manifests\dash\Period;
use Bitmovin\api\model\manifests\dash\VideoAdaptationSet;

use Bitmovin\api\model\manifests\hls\HlsManifest;
use Bitmovin\api\model\manifests\hls\MediaInfo;
use Bitmovin\api\model\manifests\hls\StreamInfo;

require_once __DIR__ . '/vendor/autoload.php';


/******************************************************************************************************************************************
 * ********************************************************functions***********************************************************************
 ******************************************************************************************************************************************/
/**
 * @param string    $name
 * @param string    $profile
 * @param integer   $bitrate
 * @param float     $rate
 * @param integer   $width
 * @param integer   $height
 * @return H264VideoCodecConfiguration
 * @throws BitmovinException
 */
function createH264VideoCodecConfiguration($apiClient,$name, $profile, $bitrate, $width = null, $height = null, $rate = null){
    $codecConfigVideo = new H264VideoCodecConfiguration($name, $profile, $bitrate, $rate);
    $codecConfigVideo->setDescription($bitrate . '_' . $name);
    $codecConfigVideo->setWidth($width);
    $codecConfigVideo->setHeight($height);
    return $apiClient->codecConfigurations()->videoH264()->create($codecConfigVideo);
}

/**
 * @param string    name
 * @param string    profile
 * @param integer   bitrate
 * @param float     rate
 * @param integer   width
 * @param integer   height
 * @return H265VideoCodecConfiguration
 * @throws BitmovinException
 */
function createH265VideoCodecConfiguration($apiClient,$name,$bitrate, $width = null, $height = null, $rate= null){
    $codecConfigVideo = new H265VideoCodecConfiguration($name, H265Profile::MAIN, $bitrate, null);
    $codecConfigVideo->setDescription($bitrate . '_' . $name);
    $codecConfigVideo->setWidth($width);
    $codecConfigVideo->setHeight($height);
    $codecConfigVideo->setRate($rate);
    return $apiClient->codecConfigurations()->videoH265()->create($codecConfigVideo);
}

/**
 * @param string    name
 * @param integer   bitrate
 * @param integer   rate
 * @return AACAudioCodecConfiguration
 * @throws BitmovinException
 */
function createAACAudioCodecConfiguration($apiClient, $name, $bitrate, $rate = null){
    $codecConfigAudio = new AACAudioCodecConfiguration($name, $bitrate, $rate);
    return $apiClient->codecConfigurations()->audioAAC()->create($codecConfigAudio);
}

/**
 * @param Encoding          encoding
 * @param Stream            stream
 * @param EncodingOutput    encodingOutput
 * @param string            initSegmentName
 * @param int               segmentDuration
 * @param string            segmentNaming
 * @return encode FMP4Muxing
 * @throws BitmovinException
 */
function createFmp4Muxing($apiClient, $encoding, Stream $stream, EncodingOutput $encodingOutput = null, $initSegmentName = 'init.mp4', $segmentDuration = 4, $segmentNaming = 'segment_%number%.m4s'){
    $muxingStream = new MuxingStream();
    $muxingStream->setStreamId($stream->getId());

    $encodingOutputs = null;
    if ($encodingOutput instanceof EncodingOutput)
    {
        $encodingOutputs = array($encodingOutput);
    }

    $fmp4Muxing = new FMP4Muxing();
    $fmp4Muxing->setInitSegmentName($initSegmentName);
    $fmp4Muxing->setSegmentLength($segmentDuration);
    $fmp4Muxing->setSegmentNaming($segmentNaming);
    $fmp4Muxing->setOutputs($encodingOutputs);
    $fmp4Muxing->setStreams(array($muxingStream));

    return $apiClient->encodings()->muxings($encoding)->fmp4Muxing()->create($fmp4Muxing);
}

/**
 * @param outputPath
 * @param muxingOutputPath
 * @return string
 */
function getSegmentOutputPath($outputPath, $muxingOutputPath){
    $segmentPath = $muxingOutputPath;
    $substr = substr($muxingOutputPath, 0, strlen($outputPath));
    if ($substr === $outputPath)
    {
        $segmentPath = substr($muxingOutputPath, strlen($outputPath));
    }
    return $segmentPath;
}

 /**
 * @param Encoding   encoding
 * @param FMP4Muxing fmp4Muxing
 * @param string     manifestType
 * @param string     segmentPath
 * @param boolean    segmentPath
 * @param CencDrm    drm
 * @return DashRepresentation
 */
function createDashRepresentation(Encoding $encoding, FMP4Muxing $fmp4Muxing, $manifestType, $segmentPath, $drm, CencDrm $cencDrm=NULL){
    $representation = new DashDrmRepresentation();
    $representation->setType($manifestType);
    $representation->setSegmentPath($segmentPath);
    $representation->setEncodingId($encoding->getId());
    $representation->setMuxingId($fmp4Muxing->getId());
    if($drm){
        $representation->setDrmId($cencDrm->getId());
    }    

    return $representation;
}


/**
 * @param Encoding         encoding
 * @param FMP4Muxing       fmp4EncodingMuxing
 * @param                  key
 * @param                  kid
 * @param                  widevinePssh
 * @param                  playreadyLaUrl
 * @param EncodingOutput[] outputs
 * @return encode CencDrm
 */
function createCencDrm($apiClient, $encoding, $fmp4EncodingMuxing, $key, $kid, array $outputs, $widevinePssh = null, $playreadyLaUrl = null){
    //CREATE CENC DRM CONFIGURATION
    $cencDrm = new CencDrm($key, $kid, $outputs);

    if (!is_null($widevinePssh))
    {
        $cencDrm->setWidevine(new CencWidevine($widevinePssh));
    }
    if (!is_null($playreadyLaUrl))
    {
        $cencDrm->setPlayReady(new CencPlayReady($playreadyLaUrl));
    }

    return $apiClient->encodings()->muxings($encoding)->fmp4Muxing()->drm($fmp4EncodingMuxing)->cencDrm()->create($cencDrm);
}

/**
 * @param Encoding   encoding
 * @param CencDrm    cencDrm
 * @param FMP4Muxing fmp4Muxing
 * @return ContentProtection
 */
function createContentProtectionForAdaptationSet(Encoding $encoding, CencDrm $cencDrm, FMP4Muxing $fmp4Muxing){
    $contentProtection = new ContentProtection();
    $contentProtection->setDrmId($cencDrm->getId());
    $contentProtection->setMuxingId($fmp4Muxing->getId());
    $contentProtection->setEncodingId($encoding->getId());

    return $contentProtection;
}


// CREATE VIDEO STREAMS FMP4 private function
/**
 * @param Encoding   encoding
 * @param codecConfigVideo VideoCodecConfiguration
 * @param inputStreamVideo INPUT STREAM FOR VIDEO
 * @return Video stream
 */
function CreateSreamsFmp4($apiClient, $encoding, $codecConfigVideo, $inputStreamVideo){
    
   $fmp4VideoStream = new Stream($codecConfigVideo, array($inputStreamVideo));

   return $apiClient->encodings()->streams($encoding)->create($fmp4VideoStream);

}

/**
* @param Encoding encoding
* @param Stream   stream
* @param TSMuxing tsMuxing
* @param string   audioGroupId
* @param string   subtitleGroupId
* @param string   segmentPath
* @param string   uri
* @return encoding StreamInfo
*/
function createHlsVariantStreamInfo($apiClient, Encoding $encoding, $masterPlaylist, Stream $stream, FMP4Muxing $tsMuxing, $audioGroupId, $segmentPath, $uri, $drm, CencDrm $cencDrm=NULL){
    $variantStream = new StreamInfo();
    $variantStream->setEncodingId($encoding->getId());
    $variantStream->setStreamId($stream->getId());
    $variantStream->setMuxingId($tsMuxing->getId());
    $variantStream->setAudio($audioGroupId);
    $variantStream->setSegmentPath($segmentPath);
    $variantStream->setUri($uri);
    if($drm){
        $variantStream->setDrmId($cencDrm->getId());
    }    


    return $apiClient->manifests()->hls()->createStreamInfo($masterPlaylist, $variantStream);
}

function createEncodingOutput(Output $output, $outputPath, $acl = AclPermission::ACL_PUBLIC_READ){
    $encodingOutput = new EncodingOutput($output);
    $encodingOutput->setOutputPath($outputPath);
    $encodingOutput->setAcl(array(new Acl($acl)));
    return $encodingOutput;
}
