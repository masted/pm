<?php

class PmRemoteServerDev {
use Options;

  /**
   * @var PmLocalServerConfigDev
   */
  public $locaConfig;

  /**
   * @var PmRemoteServerConfig
   */
  public $remoteConfig;

  function __construct(PmRemoteServerConfig $remoteServerConfig, array $options = []) {
    $this->locaConfig = O::get('PmLocalServerConfigDev');
    $this->remoteConfig = $remoteServerConfig;
    $this->setOptions($options);
  }

  function a_updateConfig() {
    $this->uploadFileRenamed($this->remoteConfig->getFile(), 'server.php', 'config');
    $this->uploadFolder2($this->locaConfig->r['pmPath'].'/defaultWebserverRecords', $this->remoteConfig->r['ngnEnvPath']);
  }

  function a_firstEnvSetup() {
    $this->remoteSshCommand('mkdir -p '.$this->remoteConfig->r['backupPath']);
    foreach ([
      'projectsPath', 'tempPath', 'logsPath', 'configPath', 'webserverProjectsConfigFolder'
    ] as $v) {
      $this->remoteSshCommand('mkdir '.$this->remoteConfig->r[$v]);
    }
    $this->a_updateEnv();
  }

  function a_updateEnv() {
    $this->a_updateConfig();
    $this->a_updateNgn();
    $this->a_updateVendors();
    $this->a_updatePm();
    $this->a_updateRun();
    $this->a_updateScripts();
    $this->updateEnvFolder('dummyProject');
    $this->a_updateDummyDbDump();
  }

  function a_updateVendors() {
    $this->updateEnvFolder('vendors');
  }

  function a_updateDummyProject() {
    Dir::copy($this->locaConfig->r['dummyProjectPath'], PmManager::$tempPath.'/dummyProject');
    $this->updateNgnAndVendorsConstants(PmManager::$tempPath.'/dummyProject/index.php');
    $this->uploadFolderToRoot(PmManager::$tempPath.'/dummyProject');
  }

  function a_updateNgn() {
    (new PmLocalServer())->a_updateBuild();
    Dir::copy($this->locaConfig->r['ngnPath'], PmManager::$tempPath.'/ngn');
    //$file = PmManager::$tempPath.'/ngn/init/core.php';
    //file_put_contents($file, preg_replace('/^.*\@ProdRemove.*$/m', '', file_get_contents($file)));
    $this->uploadFolderToRoot(PmManager::$tempPath.'/ngn');
    $this->remoteSshCommand($this->remoteConfig->r['pmPath'].'/pm projects local cc');
    $this->remoteSshCommand($this->remoteConfig->r['pmPath'].'/pm projects local patch');
  }

  function a_downloadNgn() {
    Dir::move($this->localDownloadFolder($this->remoteConfig->r['ngnPath']), PmManager::$downloadPath.'/ngn');
    output("downloaded to: ".PmManager::$downloadPath.'/ngn');
  }

  function a_updateMyadmin() {
    $this->updateEnvFolder('myadmin');
  }

  function a_updateDummyDbDump() {
    $this->uploadFile(PmCore::prepareDummyDbDump());
  }

  /**
   * @options folder
   */
  function a_updateEnvFolder() {
    Arr::checkEmpty($this->options, 'folder');
    $this->updateEnvFolder($this->options['folder']);
  }

  protected function updateEnvFolder($folderName) {
    $this->uploadFolderToRoot($this->locaConfig->r[$folderName.'Path']);
    $this->makeExecutables($folderName);
  }

  protected $executabels = [
    'pm'  => ['pm'],
    'run' => ['run.php', 'site.php'],
  ];

  protected function makeExecutables($folderName) {
    if (isset($this->executabels[$folderName])) foreach ($this->executabels[$folderName] as $filename) $this->remoteSshCommand('chmod +x '.$this->remoteConfig->r[$folderName.'Path'].'/'.$filename);
  }

  public $masterProjectDomain = 'myninja.ru';

  function a_updatePm() {
    Dir::copy($this->locaConfig->r['pmPath'], PmManager::$tempPath.'/pm');
    $tempPmPath = PmManager::$tempPath.'/pm';
    File::replaceTttt($tempPmPath.'/pm', $this->remoteConfig->r);
    $this->updateNgnAndVendorsConstants($tempPmPath.'/common-init.php');
    $this->updateNgnAndVendorsConstants($tempPmPath.'/web/init.php');
    PmLocalProjectFs::updateDbConfig($tempPmPath.'/web', O::get('PmRemoteProjectConfigDev', $this->remoteConfig->name, $this->masterProjectDomain)->r);
    $this->uploadFolderToRoot($tempPmPath);
    $this->remoteSshCommand('chmod -R 0777 '.$this->remoteConfig->r['pmPath'].'/web');
    $this->makeExecutables('pm');
    $this->remoteSshCommand($this->remoteConfig->r['runPath'].'/run.php genPmPassword');
  }

  function a_updateRun() {
    Dir::copy($this->locaConfig->r['runPath'], PmManager::$tempPath.'/run');
    $tempRunPath = PmManager::$tempPath.'/run';
    $this->updateNgnAndVendorsConstants($tempRunPath.'/run.php');
    $this->updateNgnAndVendorsConstants($tempRunPath.'/siteStandAloneInit.php');
    $p = explode('{domain}', $this->remoteConfig->r['webroot']);
    $webroot = "'{$p[0]}'.\$_SERVER['argv'][1]".(!empty($p[1]) ? "'{$p[1]}'" : '');
    Config::updateConstant($tempRunPath.'/siteStandAloneInit.php', 'WEBROOT_PATH', $webroot, false);
    $this->uploadFolderToRoot($tempRunPath);
    $this->makeExecutables('run');
  }

  function a_updateScripts() {
    $this->executabels['scripts'] = Dir::files($this->locaConfig->r['scriptsPath']);
    $this->updateEnvFolder('scripts');
  }

  /**
   * @options domain
   */
  function a_updateInstallEnv() {
    Arr::checkEmpty($this->options, 'domain');
    Dir::copy($this->locaConfig->r['ngnEnvPath'].'/install-env', PmManager::$tempPath.'/install-env');
    foreach (glob(PmManager::$tempPath.'/install-env/*') as $file) {
      $c = file_get_contents($file);
      $c = str_replace('{domain}', $this->options['domain'], $c);
      $c = str_replace('{user}', $this->remoteConfig->r['sshUser'], $c);
      file_put_contents($file, $c);
    }
    $this->uploadFolder(PmManager::$tempPath.'/install-env', 'projects/'.$this->options['domain']);
  }

  protected function updateNgnAndVendorsConstants($file) {
    Config::updateConstant($file, 'NGN_PATH', $this->remoteConfig->r['ngnPath']);
    //Config::updateConstant($file, 'VENDORS_PATH', $this->oRSCD->r['vendorsPath']);
  }

  /**
   * @options folder
   */
  function a_uploadFolderToRoot() {
    Arr::checkEmpty($this->options, 'folder');
    $this->uploadFolderToRoot($this->locaConfig->r['ngnEnvPath'].'/'.$this->options['folder']);
  }

  protected function uploadFolderToRoot($folder) {
    $folderName = basename($folder);
    $ftpRoot = $this->ftpInit();
    $this->ftp->upload(Zip::archive(PmManager::$tempPath, $folder, $folderName.'.zip'), $ftpRoot);
    $this->remoteSshCommand('rm -r $ngnEnvPath/'.$folderName);
    $this->remoteSshCommand('unzip -o $ngnEnvPath/'.$folderName.'.zip -d $ngnEnvPath');
    $this->remoteSshCommand('rm $ngnEnvPath/'.$folderName.'.zip');
  }

  function uploadFolder($folder, $fromRootPath) {
    $folderName = basename($folder);
    $ftpRoot = $this->ftpInit();
    $this->ftp->upload(Zip::archive(PmManager::$tempPath, $folder, "$folderName.zip"), $ftpRoot.'/'.$fromRootPath);
    $this->remoteSshCommand("rm -r \$ngnEnvPath/$fromRootPath/$folderName");
    $this->remoteSshCommand("unzip -o \$ngnEnvPath/$fromRootPath/$folderName.zip -d \$ngnEnvPath/$fromRootPath");
    $this->remoteSshCommand("rm \$ngnEnvPath/$fromRootPath/$folderName.zip");
  }

  function uploadFolder2($folder, $path) {
    $folderName = basename($folder);
    $this->ftpInit();
    $this->ftp->upload(Zip::archive(PmManager::$tempPath, $folder, "$folderName.zip"), $path);
    $this->remoteSshCommand("rm -r $path/$folderName");
    $this->remoteSshCommand("unzip -o $path/$folderName.zip -d $path");
    $this->remoteSshCommand("rm $path/$folderName.zip");
  }

  protected function uploadFileRenamed($file, $newname, $toFolder = '') {
    $tempFile = PmManager::$tempPath.'/'.$newname;
    copy($file, $tempFile);
    $this->uploadFile($tempFile, $toFolder);
  }

  function uploadFile($file, $toFolder = '') {
    $ftpRoot = $this->ftpInit();
    $this->ftp->upload($file, $ftpRoot.($toFolder ? '/'.$toFolder : $toFolder));
  }

  function uploadFile2($file, $path) {
    $this->ftpInit();
    $this->ftp->upload($file, $path);
  }

  function uploadFileArchived($file, $toFolder = '') {
    $archfilename = basename($file).'.zip';
    $toFolder = Misc::trimSlashes($toFolder);
    if ($toFolder) $toFolder = '/'.$toFolder;
    $archive = Zip::archive(PmManager::$tempPath, $file, $archfilename);
    $this->uploadFile($archive, $toFolder);
    $this->remoteSshCommand("unzip -o \$ngnEnvPath$toFolder/$archfilename -d \$ngnEnvPath$toFolder");
    $this->remoteSshCommand("rm \$ngnEnvPath$toFolder/$archfilename");
    return $toFolder.'/'.basename($file);
  }

  /**
   * @var Ftp
   */
  protected $ftp;

  protected function ftpInit() {
    $this->ftp = new Ftp();
    $this->ftp->server = $this->remoteConfig->r['host'];
    $this->ftp->user = $this->remoteConfig->r['ftpUser'];
    $this->ftp->password = $this->remoteConfig->r['ftpPass'];
    $this->ftp->tempPath = PmManager::$tempPath;
    if (!$this->ftp->connect()) throw new Exception('Could not connect');
    return $this->remoteConfig->r['ftpRoot'];
  }

  function remoteSshCommand($cmd) {
    PmCore::remoteSshCommand($this->remoteConfig, $cmd);
  }

  protected function getMysqlAuthStr() {
    return "-hlocalhost -u{$this->remoteConfig->r['dbUser']} -p{$this->remoteConfig->r['dbPass']}";
  }

  function remoteMysqlImport($dbName, $file) {
    $u = $this->getMysqlAuthStr();
    $this->remoteSshCommand("mysqladmin --force $u drop $dbName");
    $this->remoteSshCommand2("
mysql $u -e \"CREATE DATABASE $dbName DEFAULT CHARACTER SET ".DB_CHARSET." COLLATE ".DB_COLLATE."\"
mysql $u --default_character_set utf8 $dbName < $file
");
  }

  function remoteSshCommand2($cmd) {
    file_put_contents(PmManager::$tempPath.'/cmd', PmCore::prepareCmd($this->remoteConfig, $cmd));
    $this->uploadFile(PmManager::$tempPath.'/cmd', 'temp');
    $this->remoteSshCommand('chmod +x '.$this->remoteConfig->r['tempPath'].'/cmd');
    $this->remoteSshCommand($this->remoteConfig->r['tempPath'].'/cmd');
    $this->remoteSshCommand('rm '.$this->remoteConfig->r['tempPath'].'/cmd');
  }

  function archive($remotePath, array $excludeDirs = []) {
    $name = basename($remotePath);
    $filename = basename($remotePath).'.tgz';
    $archive = "{$this->remoteConfig->r['tempPath']}/$filename";
    $this->remoteSshCommand("rm -$archive");
    $this->remoteSshCommand("tar ".St::enum($excludeDirs, '', '` --exclude `.$v')." -C ".dirname($remotePath)." -czf $archive $name");
    return $archive;
  }

  /**
   * Скачивает каталог с удаленного сервера на текущий
   *
   * @param unknown_type Сервер, с которого нужно скачать
   * @param unknown_type Каталог, который необходимо скачать
   * @param unknown_type Каталог, в который необходимо переписать скачанный
   */
  function downloadFolder(PmRemoteServerDev $oFromServer, $fromPath, $toFolder) {
    output("Downloading '$fromPath' to '$toFolder'...");
    $fromArchive = $oFromServer->archive($fromPath);
    $this->remoteSshCommand("mkdir -p $toFolder");
    $toArchive = $toFolder.'/'.basename($fromArchive);
    $this->_downloadFile($oFromServer, $fromArchive, $toArchive);
    $this->remoteSshCommand("tar -C $toFolder -xvf $toArchive");
    $this->remoteSshCommand("rm $toArchive");
    return $toFolder.'/'.basename($fromPath);
  }

  function _downloadFile(PmRemoteServerDev $oFromServer, $fromPath, $toPath) {
    $r = $oFromServer->remoteConfig->r;
    $this->remoteSshCommand2("lftp -u {$r['ftpUser']},{$r['ftpPass']} {$r['host']} -e \"get $fromPath -o $toPath; exit\"");
  }

  function downloadFile(PmRemoteServerDev $oFromServer, $fromPath, $toFolder) {
    $this->_downloadFile($oFromServer, $oFromServer->archive($fromPath), "$toFolder/arch.tgz");
    $this->remoteSshCommand("tar -C $toFolder -xvf $toFolder/arch.tgz");
    $this->remoteSshCommand("rm $toFolder/arch.tgz");
  }

  function downloadDb(PmRemoteServerDev $oFromServer, $dbName) {
    $remoteDumpPath = $oFromServer->dumpDb($dbName);
    $this->downloadFile($oFromServer, $remoteDumpPath, $this->remoteConfig->r['tempPath']);
    return $this->remoteConfig->r['tempPath'].'/'.basename($remoteDumpPath);
  }

  function localDownloadProjectFolder($webroot) {
    return $this->localDownloadFolder($webroot, ['u/*', 'temp/*', 'cache/*']);
  }

  function localDownloadFolder($remotePath, array $exclude = []) {
    $archive = $this->archive($remotePath, $exclude);
    $localPath = PmManager::$tempPath.'/'.basename($archive);
    $this->ftpInit();
    $this->ftp->download($localPath, $archive);
    $extracted = (new Tzg(PmManager::$tempPath))->extract($localPath, PmManager::$tempPath);
    return $extracted[0];
  }

  function localDownloadFile($remotePath) {
    $name = basename($remotePath);
    $filename = basename($remotePath).'.tgz';
    $archive = "{$this->remoteConfig->r['tempPath']}/$filename";
    $this->remoteSshCommand("tar -C ".dirname($remotePath)." -czf $archive $name");
    $this->ftpInit();
    $this->ftp->download(PmManager::$tempPath.'/'.$filename, $archive);
    $extracted = (new Tzg(PmManager::$tempPath))->extract(PmManager::$tempPath.'/'.$filename, PmManager::$tempPath);
    return $extracted[0];
  }

  function dumpDb($dbName) {
    $remoteDumpFile = "{$this->remoteConfig->r['tempPath']}/$dbName";
    $this->remoteSshCommand2("mysqldump {$this->getMysqlAuthStr()} $dbName > $remoteDumpFile");
    return $remoteDumpFile;
  }

  function localDownloadDb($dbName) {
    return $this->localDownloadFile($this->dumpDb($dbName));
  }

}
