<?php

/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */
class admin_plugin_elwikisync extends DokuWiki_Admin_Plugin {

    protected $profiles = array();
    protected $profno = '';
    protected $client = null;
    protected $apiversion = 0;
    protected $defaultTimeout = 15;

    /**
     * Constructor.
     */
    function __construct(){
        $this->_profileLoad();
        $this->profno = preg_replace('/[^0-9]+/', '', (string) ($_REQUEST['no'] ?? ''));
        // ignore profile numbers that do not exist (e.g. after deleting a profile)
        if($this->profno !== '' && !isset($this->profiles[$this->profno]) && !isset($_REQUEST['prf'])){
            $this->profno = '';
        }
    }

    /**
     * Get a single value of the current (or given) profile
     *
     * @param string $key
     * @param mixed $default
     * @param string|int|null $no profile number, defaults to the current profile
     * @return mixed
     */
    protected function _prf($key, $default = '', $no = null){
        if($no === null) $no = $this->profno;
        if($no === '' || !isset($this->profiles[$no]) || !is_array($this->profiles[$no])) return $default;
        return $this->profiles[$no][$key] ?? $default;
    }

    /**
     * Flush output buffers without triggering a notice when there is no buffer
     */
    protected function _flush(){
        if(ob_get_level() > 0) @ob_flush();
        flush();
    }

    function _connect(){
        if(!is_null($this->client)) return true;
        if($this->profno === '' || !isset($this->profiles[$this->profno])) return false;

        $timeout = $this->_prf('timeout', '');
        if(is_numeric($timeout)){
            $timeout = (int) $timeout;
        } else {
            $timeout = $this->defaultTimeout;
        }

        $server = $this->_prf('server');
        if(class_exists('IXR_Client')) {
            $this->client = new IXR_Client($server, false, 80, $timeout);
        }
        else {
            $this->client = new dokuwiki\Remote\IXR\Client($server);
            $this->client->timeout = $timeout;
        }

        // do the login
        if($this->_prf('user') !== ''){
            $ok = $this->client->query('dokuwiki.login',
                    $this->_prf('user'),
                    $this->_prf('pass')
                  );
            if(!$ok){
                msg($this->getLang('xmlerr').' '.hsc($this->client->getErrorMessage()),-1);
                $this->client = null;
                return false;
            }
            if(!$this->client->getResponse()){
                msg($this->getLang('loginerr'),-1);
                $this->client = null;
                return false;
            }
        }

        $ok = $this->client->query('dokuwiki.getXMLRPCAPIVersion');
        if(!$ok){
            msg($this->getLang('xmlerr').' '.hsc($this->client->getErrorMessage()),-1);
            $this->client = null;
            return false;
        }
        $this->apiversion = (int) $this->client->getResponse();
        if($this->apiversion < 1){
            msg($this->getLang('versionerr'),-1);
            $this->client = null;
            return false;
        }

        return true;
    }

    /**
     * return sort order for position in admin menu
     */
    function getMenuSort() {
        return 1020;
    }

    /**
     * handle profile saving/deleting
     */
    function handle() {
        if(isset($_REQUEST['prf']) && is_array($_REQUEST['prf'])){
            if(isset($_REQUEST['elwikisync__delete']) && $this->profno !== ''){
                // delete profile
                unset($this->profiles[$this->profno]);
                $this->profiles = array_values($this->profiles); //reindex
                $this->profno = '';
            }elseif(isset($_REQUEST['elwikisync__delete'])){
                // delete clicked on the "new profile" form - nothing to do
                return;
            }else{
                // add/edit profile
                if($this->profno === '') $this->profno = count($this->profiles);
                $prf = $_REQUEST['prf'];
                if ( !isset($prf['timeout']) || !is_numeric($prf['timeout']) ){
                  $prf['timeout'] = $this->defaultTimeout;
                }
                // note: resaving intentionally resets the sync times (see 'changewarn')
                $this->profiles[$this->profno] = $prf;
            }
            $this->_profileSave();

            // reset the client
            $this->client = null;
        }
    }

    /**
     * output appropriate html
     */
    function html() {
        $syncPages = (isset($_POST['elwikisync_pages']) && is_array($_POST['elwikisync_pages'])) ? $_POST['elwikisync_pages'] : array();
        $syncMedia = (isset($_POST['elwikisync_media']) && is_array($_POST['elwikisync_media'])) ? $_POST['elwikisync_media'] : array();

        if((count($syncPages) || count($syncMedia)) && $this->profno!==''){
            // do the sync
            echo $this->locale_xhtml('sync');

            //show progressbar
            echo '<div class="centeralign" id="dw__loading">'.NL;
            echo '<script type="text/javascript" charset="utf-8"><!--//--><![CDATA[//><!--'.NL;
            echo 'showLoadBar();'.NL;
            echo '//--><!]]></script>'.NL;
            echo '<br /></div>'.NL;
            $this->_flush();

            echo '<ul class="elwikisync">';

            if(count($syncPages)){
                $this->_sync($syncPages,'pages');
            }
            if(count($syncMedia)){
                $this->_sync($syncMedia,'media');
            }
            $this->_saveSyncTimes((int) ($_POST['lnow'] ?? 0),
                                 (int) ($_POST['rnow'] ?? 0));

            echo '</ul>';

            //hide progressbar
            echo '<script type="text/javascript" charset="utf-8"><!--//--><![CDATA[//><!--'.NL;
            echo 'hideLoadBar("dw__loading");'.NL;
            echo '//--><!]]></script>'.NL;
            $this->_flush();


            echo '<p>'.$this->getLang('syncdone').'</p>';
        }elseif(!empty($_REQUEST['startsync']) && $this->profno!==''){
            // get sync list
            list($lnow,$rnow) = $this->_getTimes();
            $pages = array();
            $media = array();
            $type = (int) $this->_prf('type', 0);
            if($rnow){
                if($type == 0 || $type == 1){
                    $pages = $this->_getSyncList('pages');
                }
                if(($type == 0 || $type == 2) && $pages !== false ){
                    $media = $this->_getSyncList('media');
                }
            }

            if ( $pages === false || $media === false ){
              return;
            }

            if(count($pages) || count($media)){
                $this->_directionFormStart($lnow,$rnow);
                if(count($pages))
                    $this->_directionForm('pages',$pages);
                if(count($media))
                    $this->_directionForm('media',$media);

                $this->_directionFormEnd();
            }else{
                echo $this->locale_xhtml('nochange');
            }
        }else{
            echo $this->locale_xhtml('intro');

            echo '<div class="elwikisync_left">';
            $this->_profilelist($this->profno);
            if($this->profno !=='' ){
                echo '<br />';
                $this->_profileView($this->profno);
            }
            echo '</div>';
            echo '<div class="elwikisync_right">';
            $this->_profileform($this->profno);
            echo '</div>';
        }
    }

    /**
     * Load profiles from serialized storage
     */
    function _profileLoad(){
        global $conf;
        $this->profiles = array();
        $profiles = $conf['metadir'].'/elwikisync.profiles';
        if(!file_exists($profiles)){
            // first run: take over the profiles of the original sync plugin (read only,
            // the original file is never modified - ours is written on the next save)
            $legacy = $conf['metadir'].'/sync.profiles';
            if(file_exists($legacy)) $profiles = $legacy;
        }
        if(file_exists($profiles)){
            // a serialized array always ends with "}" - trailing whitespace
            // (e.g. a newline added by an editor) would make unserialize() complain
            $raw  = rtrim((string) io_readFile($profiles,false));
            $data = ($raw === '') ? array() : @unserialize($raw, array('allowed_classes' => false));
            if(is_array($data)){
                $this->profiles = $data;
            }else{
                msg('elwikisync plugin: could not read '.hsc($profiles).' - file is damaged',-1);
            }
        }
    }

    /**
     * Save profiles to serialized storage
     */
    function _profileSave(){
        global $conf;
        $profiles = $conf['metadir'].'/elwikisync.profiles';
        io_saveFile($profiles,serialize($this->profiles));
    }

    /**
     * Check connection for choosen profile and display last sync date.
     */
    function _profileView(){
        if(!$this->_connect()) return false;

        $no = $this->profno;

        $ok = $this->client->query('dokuwiki.getVersion');
        $version = '';
        if($ok) $version = $this->client->getResponse();

        echo '<form action="" method="post">';
        echo '<input type="hidden" name="no" value="'.hsc($no).'" />';
        echo '<fieldset><legend>'.$this->getLang('syncstart').'</legend>';
        if($version){
            echo '<p>'.$this->getLang('remotever').' '.hsc($version).'</p>';
            if($this->_isReadonly()){
                echo '<p><strong>'.$this->getLang('readonly').'</strong></p>';
            }
            $ltime = (int) $this->_prf('ltime', 0);
            if($ltime){
                echo '<p>'.$this->getLang('lastsync').' '.hsc(dformat($ltime)).'</p>';
            }else{
                echo '<p>'.$this->getLang('neversync').'</p>';
            }
            echo '<input name="startsync" type="submit" value="'.$this->getLang('syncstart').'" class="button" />';
        }else{
            echo '<p class="error">'.$this->getLang('noconnect').'<br />'.hsc($this->client->getErrorMessage()).'</p>';
        }
        echo '</fieldset>';
        echo '</form>';
        return true;
    }

    /**
     * Dropdown list of available sync profiles
     */
    function _profilelist($no=''){
        echo '<form action="" method="post">';
        echo '<fieldset><legend>'.$this->getLang('profile').'</legend>';
        echo '<select name="no" class="edit">';
        echo '  <option value="">'.$this->getLang('newprofile').'</option>';
        foreach($this->profiles as $pno => $opts){
            if(!is_array($opts)) continue;
            $srv  = parse_url((string) ($opts['server'] ?? ''));
            $host = is_array($srv) ? ($srv['host'] ?? '') : '';
            $user = (string) ($opts['user'] ?? '');
            $ns   = (string) ($opts['ns'] ?? '');

            echo '<option value="'.hsc($pno).'" '.(($no!=='' && $pno == $no)?'selected="selected"':'').'>';
            echo ($pno+1).'. ';
            if($user !== '') echo hsc($user).'@';
            echo hsc($host);
            if($ns !== '') echo ':'.hsc($ns);
            if(!empty($opts['readonly'])) echo ' ('.$this->getLang('readonlyshort').')';
            echo '</option>';
        }
        echo '</select>';
        echo '<input type="submit" value="'.$this->getLang('select').'" class="button" />';
        echo '</fieldset>';
        echo '</form>';
    }

    /**
     * Form to edit or create a sync profile
     */
    function _profileform($no=''){
        $server  = (string) $this->_prf('server', '', $no);
        $ns      = (string) $this->_prf('ns', '', $no);
        $depth   = (int) $this->_prf('depth', 0, $no);
        $user    = (string) $this->_prf('user', '', $no);
        $pass    = (string) $this->_prf('pass', '', $no);
        $timeout = $this->_prf('timeout', $this->defaultTimeout, $no);
        $type    = (int) $this->_prf('type', 0, $no);
        $ltime   = (int) $this->_prf('ltime', 0, $no);
        $readonly = $this->_isReadonly($no);

        echo '<form action="" method="post" class="elwikisync_profile">';
        echo '<fieldset><legend>';
        if($no !== ''){
            echo $this->getLang('edit');
        }else{
            echo $this->getLang('create');
        }
        echo '</legend>';

        echo '<input type="hidden" name="no" value="'.hsc($no).'" />';

        echo '<label for="elwikisync__server">'.$this->getLang('server').'</label> ';
        echo '<input type="text" name="prf[server]" id="elwikisync__server" class="edit" value="'.hsc($server).'" />';
        echo '<samp>http://example.com/dokuwiki/lib/exe/xmlrpc.php</samp>';

        echo '<label for="elwikisync__ns">'.$this->getLang('ns').'</label> ';
        echo '<input type="text" name="prf[ns]" id="elwikisync__ns" class="edit" value="'.hsc($ns).'" />';

        echo '<label for="elwikisync__depth">'.$this->getLang('depth').'</label> ';
        echo '<select name="prf[depth]" id="elwikisync__depth" class="edit">';
        for($i = 0; $i <= 3; $i++){
            echo '<option value="'.$i.'" '.(($depth == $i)?'selected="selected"':'').'>'.$this->getLang('level'.$i).'</option>';
        }
        echo '</select>';


        echo '<label for="elwikisync__user">'.$this->getLang('user').'</label> ';
        echo '<input type="text" name="prf[user]" id="elwikisync__user" class="edit" value="'.hsc($user).'" />';

        echo '<label for="elwikisync__pass">'.$this->getLang('pass').'</label> ';
        echo '<input type="password" name="prf[pass]" id="elwikisync__pass" class="edit" value="'.hsc($pass).'" />';

        echo '<label for="elwikisync__timeout">'.$this->getLang('timeout').'</label>';
        echo '<input type="number" name="prf[timeout]" id="elwikisync__timeout" class="edit" value="'.hsc((string) $timeout).'" />';

        echo '<span>'.$this->getLang('type').'</span>';

        echo '<div class="type">';
        for($i = 0; $i <= 2; $i++){
            echo '<input type="radio" name="prf[type]" id="elwikisync__type'.$i.'" value="'.$i.'" '.(($type == $i)?'checked="checked"':'').'/>';
            echo '<label for="elwikisync__type'.$i.'">'.$this->getLang('type'.$i).'</label> ';
        }
        echo '</div>';

        echo '<div class="readonly">';
        echo '<input type="checkbox" name="prf[readonly]" id="elwikisync__readonly" value="1" '.($readonly?'checked="checked"':'').'/>';
        echo '<label for="elwikisync__readonly">'.$this->getLang('readonly').'</label>';
        echo '<small>'.$this->getLang('readonlyinfo').'</small>';
        echo '</div>';


        echo '<div class="submit">';
        echo '<input type="submit" value="'.$this->getLang('save').'" class="button" />';
        if($no !== '' && $ltime){
            echo '<small>'.$this->getLang('changewarn').'</small>';
        }
        echo '</div>';

        if($no !== ''){
            echo '<div class="submit">';
            echo '<input name="elwikisync__delete" type="submit" value="'.$this->getLang('delete').'" class="button" />';
            echo '</div>';
        }

        echo '</fieldset>';
        echo '</form>';
    }

    /**
     * Is the current profile in read-only mode (pull only, never write to the remote wiki)?
     */
    protected function _isReadonly($no = null){
        return (bool) $this->_prf('readonly', 0, $no);
    }

    /**
     * Lock files that will be modified on either side.
     *
     * Files are always locked locally (any direction may change the local wiki
     * or at least reads it). Remote locks are only requested for files that are
     * actually written to the remote wiki (push / remote delete). Pulling only
     * needs read access, so a read-only remote account works fine.
     *
     * Lock fails are printed and removed from synclist
     *
     * @returns array (local locks, remote locks)
     */
    function _lockFiles(&$synclist){
        if(!$this->_connect()) return array(array(), array());
        $readonly = $this->_isReadonly();

        // lock the files locally
        $lock = array();
        $remotelock = array();
        foreach((array) $synclist as $id => $dir){
            $dir = (int) $dir;
            if($dir == 0) continue;
            if($readonly && $dir > 0) continue; // will be skipped in _sync()
            if(checklock($id)){
                $this->_listOut($this->getLang('lockfail').' '.$id,'error');
                unset($synclist[$id]);
            }else{
                lock($id); // lock local
                $lock[] = $id;
                if($dir > 0) $remotelock[] = $id;
            }
        }

        // lock remote files - only those we are going to write remotely
        if(!count($remotelock)) return array($lock, array());

        $ok = $this->client->query('dokuwiki.setLocks',array('lock'=>$remotelock,'unlock'=>array()));
        if(!$ok){
            // remote locking failed: skip all remote write operations, but keep pulls
            $this->_listOut('failed RPC communication: '.$this->client->getErrorMessage(),'error');
            foreach($remotelock as $id){
                $this->_listOut($this->getLang('lockfail').' '.$id,'error');
                unset($synclist[$id]);
                unlock($id);
            }
            $lock = array_values(array_diff($lock, $remotelock));
            return array($lock, array());
        }
        $data = $this->client->getResponse();
        $lockfail = (is_array($data) && isset($data['lockfail'])) ? (array) $data['lockfail'] : array();
        foreach($lockfail as $id){
            $this->_listOut($this->getLang('lockfail').' '.$id,'error');
            unset($synclist[$id]);
            unlock($id);
        }
        $lock       = array_values(array_diff($lock, $lockfail));
        $remotelock = array_values(array_diff($remotelock, $lockfail));

        return array($lock, $remotelock);
    }

    /**
     * Print a message as list item using the given class
     */
    function _listOut($msg,$class='ok'){
        echo '<li class="'.hsc($class).'"><div class="li">';
        echo hsc($msg);
        echo "</div></li>\n";
        $this->_flush();
    }

    /**
     * Create a Base64 object for the XML-RPC request
     * (class name differs between DokuWiki releases)
     */
    protected function _base64($data){
        if(class_exists('IXR_Base64')) return new IXR_Base64($data);
        return new \IXR\DataType\Base64($data);
    }

    /**
     * Execute the sync action and print the results
     */
    function _sync(&$synclist,$type){
        if(!$this->_connect()) return false;
        $sum = (string) ($_REQUEST['sum'] ?? '');
        $readonly = $this->_isReadonly();
        $lock = array();
        $remotelock = array();

        if($type == 'pages')
            list($lock, $remotelock) = $this->_lockFiles($synclist);

        // do the sync
        foreach((array) $synclist as $id => $dir){
            $id  = (string) $id;
            $dir = (int) $dir;
            @set_time_limit(30);
            if($dir == 0){
                $this->_listOut($this->getLang('skipped').' '.$id,'skipped');
                continue;
            }
            if($readonly && $dir > 0){
                // never write to the remote wiki in read-only mode
                $this->_listOut($this->getLang('readonlyskip').' '.$id,'skipped');
                continue;
            }
            if($dir == -2){
                //delete local
                if($type == 'pages'){
                    saveWikiText($id,'',$sum,false);
                    $this->_listOut($this->getLang('localdelok').' '.$id,'del_okay');
                }else{
                    $file = mediaFN($id);
                    if(file_exists($file) && @unlink($file)){
                        $this->_listOut($this->getLang('localdelok').' '.$id,'del_okay');
                    }else{
                        $this->_listOut($this->getLang('localdelfail').' '.$id,'del_fail');
                    }
                }
                continue;
            }
            if($dir == -1){
                //pull
                if($type == 'pages'){
                    $ok = $this->client->query('wiki.getPage',$id);
                }else{
                    $ok = $this->client->query('wiki.getAttachment',$id);
                }
                if(!$ok){
                    $this->_listOut($this->getLang('pullfail').' '.$id.' '.
                                    $this->client->getErrorMessage(),'pull_fail');
                    continue;
                }
                $data = $this->client->getResponse();
                if($type == 'pages'){
                    saveWikiText($id,(string) $data,$sum,false);
                    idx_addPage($id);
                }else{
                    if($this->apiversion < 7){
                        $data = base64_decode((string) $data);
                    }
                    io_saveFile(mediaFN($id),$data);
                }
                $this->_listOut($this->getLang('pullok').' '.$id,'pull_okay');
                continue;
            }
            if($dir == 1){
                // push
                if($type == 'pages'){
                    $data = rawWiki($id);
                    $ok = $this->client->query('wiki.putPage',$id,$data,array('sum'=>$sum));
                }else{
                    $data = io_readFile(mediaFN($id),false);
                    if($this->apiversion < 6){
                        $data = base64_encode($data);
                    }else{
                        $data = $this->_base64($data);
                    }
                    $ok = $this->client->query('wiki.putAttachment',$id,$data,array('ow'=>true));
                }
                if(!$ok){
                    $this->_listOut($this->getLang('pushfail').' '.$id.' '.
                                    $this->client->getErrorMessage(),'push_fail');
                    continue;
                }
                $this->_listOut($this->getLang('pushok').' '.$id,'push_okay');
                continue;
            }
            if($dir == 2){
                // remote delete
                if($type == 'pages'){
                    $ok = $this->client->query('wiki.putPage',$id,'',array('sum'=>$sum));
                }else{
                    $ok = $this->client->query('wiki.deleteAttachment',$id);
                }
                if(!$ok){
                    $this->_listOut($this->getLang('remotedelfail').' '.$id.' '.
                                    $this->client->getErrorMessage(),'del_fail');
                    continue;
                }
                $this->_listOut($this->getLang('remotedelok').' '.$id,'del_okay');
                continue;
            }
        }

        // unlock
        if($type == 'pages'){
            foreach($lock as $id){
                unlock($id);
            }
            if(count($remotelock)){
                $this->client->query('dokuwiki.setLocks',array('lock'=>array(),'unlock'=>$remotelock));
            }
        }

        return true;
    }

    /**
     * Save synctimes
     */
    function _saveSyncTimes($ltime,$rtime){
        $no = $this->profno;
        if($no === '' || !isset($this->profiles[$no])) return;
        list($letime,$retime) = $this->_getTimes();
        $this->profiles[$no]['ltime'] = $ltime;
        $this->profiles[$no]['rtime'] = $rtime;
        $this->profiles[$no]['letime'] = $letime;
        $this->profiles[$no]['retime'] = $retime;
        $this->_profileSave();
    }

    /**
     * Open the sync direction form and initialize the table
     */
    function _directionFormStart($lnow,$rnow){
        $no = $this->profno;
        echo $this->locale_xhtml('list');
        echo '<form action="" method="post">';
        echo '<input type="hidden" name="lnow" value="'.hsc((string) $lnow).'" />';
        echo '<input type="hidden" name="rnow" value="'.hsc((string) $rnow).'" />';
        echo '<input type="hidden" name="no" value="'.hsc((string) $no).'" />';
        echo '<table class="inline" id="elwikisync__direction__table">';
        echo '<tr>
                <th class="elwikisync__file">'.$this->getLang('file').'</th>
                <th class="elwikisync__local">'.$this->getLang('local').'</th>
                <th class="elwikisync__push" id="elwikisync__push">&gt;</th>
                <th class="elwikisync__skip" id="elwikisync__skip">=</th>
                <th class="elwikisync__pull" id="elwikisync__pull">&lt;</th>
                <th class="elwikisync__remote">'.$this->getLang('remote').'</th>
                <th class="elwikisync__diff">'.$this->getLang('diff').'</th>
              </tr>';
    }

    /**
     * Close the direction form and table
     */
    function _directionFormEnd(){
        global $lang;
        echo '</table>';
        echo '<label for="the__summary">'.$lang['summary'].'</label> ';
        echo '<input type="text" name="sum" id="the__summary" value="" class="edit" />';
        echo '<input type="submit" value="'.$this->getLang('syncstart').'" class="button" />';
        echo '</form>';
    }

    /**
     * Print a list of changed files and ask for the sync direction
     *
     * Tries to be clever about suggesting the direction
     */
    function _directionForm($type,&$synclist){
        $no = $this->profno;

        $ltime  = (int) $this->_prf('ltime', 0);
        $rtime  = (int) $this->_prf('rtime', 0);
        $letime = (int) $this->_prf('letime', 0);
        $retime = (int) $this->_prf('retime', 0);
        $readonly = $this->_isReadonly();

        foreach($synclist as $id => $item){
            $hasLocal  = isset($item['local']);
            $hasRemote = isset($item['remote']);
            $lmtime = $hasLocal  ? $this->_toTimestamp($item['local']['mtime'] ?? 0)  : 0;
            $rmtime = $hasRemote ? $this->_toTimestamp($item['remote']['mtime'] ?? 0) : 0;

            // check direction
            $dir = 0;
            if($ltime && $rtime){ // synced before
                if($rmtime > $rtime &&
                   $lmtime <= $letime){
                    $dir = -1;
                }
                if($rmtime <= $retime &&
                   $lmtime > $ltime){
                    $dir = 1;
                }
            }else{ // never synced
                if(!$lmtime && $rmtime){
                    $dir = -1;
                }
                if($lmtime && !$rmtime){
                    $dir = 1;
                }
            }
            // read-only: never suggest writing to the remote wiki
            if($readonly && $dir > 0) $dir = 0;

            $fieldname = 'elwikisync_'.$type.'['.hsc($id).']';

            echo '<tr>';

            echo '<td class="elwikisync__file">'.hsc($id).'</td>';
            echo '<td class="elwikisync__local">';
            if(!$hasLocal){
                echo '&mdash;';
            }else{
                echo '<div>'.hsc(dformat($lmtime)).'</div>';
                echo ' <div>('.(int) ($item['local']['size'] ?? 0).' bytes)</div>';
            }
            echo '</td>';

            echo '<td class="elwikisync__push">';
            if($readonly){
                echo '<span title="'.hsc($this->getLang('readonly')).'">&ndash;</span>';
            }elseif(!$hasLocal){
                echo '<input type="radio" name="'.$fieldname.'" value="2" class="elwikisyncpush" title="'.$this->getLang('pushdel').'" '.(($dir == 2)?'checked="checked"':'').' />';
            }else{
                echo '<input type="radio" name="'.$fieldname.'" value="1" class="elwikisyncpush" title="'.$this->getLang('push').'" '.(($dir == 1)?'checked="checked"':'').' />';
            }
            echo '</td>';
            echo '<td class="elwikisync__skip">';
            echo '<input type="radio" name="'.$fieldname.'" value="0" class="elwikisyncskip" title="'.$this->getLang('keep').'" '.(($dir == 0)?'checked="checked"':'').' />';
            echo '</td>';
            echo '<td class="elwikisync__pull">';
            if(!$hasRemote){
                echo '<input type="radio" name="'.$fieldname.'" value="-2" class="elwikisyncpull" title="'.$this->getLang('pulldel').'" '.(($dir == -2)?'checked="checked"':'').' />';
            }else{
                echo '<input type="radio" name="'.$fieldname.'" value="-1" class="elwikisyncpull" title="'.$this->getLang('pull').'" '.(($dir == -1)?'checked="checked"':'').' />';
            }
            echo '</td>';

            echo '<td class="elwikisync__remote">';
            if(!$hasRemote){
                echo '&mdash;';
            }else{
                echo '<div>'.hsc(dformat($rmtime)).'</div>';
                echo ' <div>('.(int) ($item['remote']['size'] ?? 0).' bytes)</div>';
            }
            echo '</td>';

            echo '<td class="elwikisync__diff">';
            if($type == 'pages'){
                echo '<a href="'.DOKU_BASE.'lib/plugins/elwikisync/diff.php?id='.rawurlencode($id).'&amp;no='.rawurlencode((string) $no).'" target="_blank" class="elwikisync_popup">'.$this->getLang('diff').'</a>';
            }
            echo '</td>';

            echo '</tr>';
        }
    }

    /**
     * Convert a time value returned by the remote API into a unix timestamp
     *
     * Depending on the remote DokuWiki version, times are returned as int,
     * as numeric string, as ISO 8601 string or as XML-RPC date object
     * (IXR_Date / IXR\DataType\Date).
     *
     * @param mixed $value
     * @return int
     */
    protected function _toTimestamp($value){
        if(is_int($value)) return $value;
        if(is_numeric($value)) return (int) $value;
        if($value instanceof \DateTimeInterface) return $value->getTimestamp();
        if(is_object($value) && method_exists($value, 'getTimeStamp')) return (int) $value->getTimeStamp();
        if(is_string($value) && $value !== ''){
            $ts = strtotime($value);
            return ($ts === false) ? 0 : $ts;
        }
        return 0;
    }

    /**
     * Get the local and remote time
     *
     * @return array (localtime, remotetime) - both 0 on failure
     */
    function _getTimes(){
        if(!$this->_connect()) return array(0,0);
        // get remote time
        $ok = $this->client->query('dokuwiki.getTime');
        if(!$ok){
            msg('Failed to fetch remote time. '.
                hsc($this->client->getErrorMessage()),-1);
            return array(0,0);
        }
        $rtime = $this->_toTimestamp($this->client->getResponse());
        $ltime = time();
        return array($ltime,$rtime);
    }

    /**
     * Get a list of changed files
     */
    function _getSyncList($type='pages'){
        if(!$this->_connect()) return array();
        global $conf;
        $list = array();
        $ns = (string) $this->_prf('ns', '');
        $depth = (int) $this->_prf('depth', 0);

        // get remote file list
        if($type == 'pages'){
            $ok = $this->client->query('dokuwiki.getPagelist',$ns,
                    array('depth' => $depth,
                          'hash' => true));
        }else{
            $ok = $this->client->query('wiki.getAttachments',$ns,
                    array('depth' => $depth,
                          'hash' => true));
        }
        if(!$ok){
            msg('Failed to fetch remote file list. '.
                hsc($this->client->getErrorMessage()),-1);
            return false;
        }
        $remote = $this->client->getResponse();
        // put into synclist
        foreach((array) $remote as $item){
            if(!is_array($item) || !isset($item['id'])) continue;
            $list[$item['id']]['remote'] = $item;
            unset($list[$item['id']]['remote']['id']);
        }
        unset($remote);

        // get local file list
        $local = array();
        $dir = utf8_encodeFN(str_replace(':', '/', $ns));
        require_once(DOKU_INC.'inc/search.php');
        if($type == 'pages'){
            search($local, $conf['datadir'], 'search_allpages',
                    array('depth' => $depth,
                          'hash' => true), $dir);
        }else{
            search($local, $conf['mediadir'], 'search_media',
                    array('depth' => $depth,
                          'hash' => true), $dir);
        }

        // put into synclist
        foreach($local as $item){
            if(!isset($item['id'])) continue;
            $id = $item['id'];
            // skip identical files
            if(isset($list[$id]['remote']['hash'], $item['hash']) &&
               $list[$id]['remote']['hash'] == $item['hash']){
                unset($list[$id]);
                continue;
            }

            $list[$id]['local'] = $item;
            unset($list[$id]['local']['id']);
        }
        unset($local);

        ksort($list);
        return $list;
    }

    /**
     * show diff between the local and remote versions of the page
     */
    function _diff($id){
        if(!$this->_connect()) return false;

        $ok = $this->client->query('wiki.getPage',$id);
        if(!$ok){
            echo $this->getLang('pullfail').' '.hsc($id).' ';
            echo hsc($this->client->getErrorMessage());
            die();
        }
        $remote = (string) $this->client->getResponse();
        $local  = (string) rawWiki($id);

        $df = new Diff(explode("\n",htmlspecialchars($local)),
                       explode("\n",htmlspecialchars($remote)));

        $tdf = new TableDiffFormatter();
        echo '<table class="diff">';
        echo '<tr>';
        echo '<th colspan="2">'.$this->getLang('local').'</th>';
        echo '<th colspan="2">'.$this->getLang('remote').'</th>';
        echo '</tr>';
        echo $tdf->format($df);
        echo '</table>';
        return true;
    }
}
//Setup VIM: ex: et ts=4 enc=utf-8 :
