<?php 

/*
 *    RunThisApp allows sharing test builds of iOS apps with testers.
 *    Copyright (C) 2011 Ludovic Landry & Pascal Cans
 *
 *    This program is free software: you can redistribute it and/or modify
 *    it under the terms of the GNU General Public License as published by
 *    the Free Software Foundation, either version 3 of the License, or
 *    (at your option) any later version.
 *
 *    This program is distributed in the hope that it will be useful,
 *    but WITHOUT ANY WARRANTY; without even the implied warranty of
 *    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *    GNU General Public License for more details.
 *
 *    You should have received a copy of the GNU General Public License
 *    along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
 
    use Entities\Application;
    use Entities\Version;
    
    require_once __DIR__ . '/../lib/cfpropertylist/CFPropertyList.php';
    require_once __DIR__ . '/../tools.php';
    require_once __DIR__ . '/../core/index.php';
    require_once __DIR__ . '/../constants.php';
    require_once __DIR__ . '/../lib/PngCompote/PngCompote.php';
        

    function unzipApplication($upload_path, $filename) {

            $zip = new ZipArchive;
            $res = $zip->open($upload_path . $filename);
            if ($res === TRUE) {

                $appName = substr($filename, 0, -4);
                $zip->extractTo($upload_path . $appName . '/');
                $zip->close();

                return true;

            } else {
                return false;
            }
    }

?>

<!doctype html>
<html>
<head>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<title>Run This App | Add Application</title>
	<link href="../css/style-0001.css" media="screen" type="text/css" rel="stylesheet">
</head>
<body>

	<?php include __DIR__ . '/../header.php';?>
	
	<div id="content" class="box">
		<div class="boxtop"></div>
		<div class="column first">
			<div class="boxheader">			
				<h2>Add New Application</h2>
			</div>
			<div>
				
<?php

    // If we have uploaded a file we process it else we ask for it
if (!empty($_FILES['app_file'])) {

    if ($_FILES['app_file']['error'] > 0) {
        if ($_FILES['app_file']['error'] == 1) {
            echo 'Error: The uploaded file exceeds the upload_max_filesize directive in php.ini.<br />';
        } else if ($_FILES['app_file']['error'] == 4) {
            echo 'Error: No file was uploaded.<br />';
        } else {
            echo 'Error: code #' . $_FILES['app_file']['error'] . '<br />';
        }
    } else {

        //echo 'Upload: ' . $_FILES['app_file']['name'] . '<br />';
        echo 'Type: ' . $_FILES['app_file']['type'] . '<br />';
        echo 'Size: ' . ($_FILES['app_file']['size'] / 1024) . ' Kb<br />';

        $error = '';

        // Configuration options
        $allowed_filetypes = array('jpg', 'png', 'ipa'); // Types of file allowed
        $max_filesize = 20971520; // Maximum filesize in BYTES (currently 20MB)

        $folder_token = Tools::randomAppleRequestId();

        $filename = $_FILES['app_file']['name']; // Get the name of the file (including file extension).
        $ext = pathinfo($filename, PATHINFO_EXTENSION); // Get the extension from the filename.
        // Check if the filetype is allowed
        if (!in_array($ext, $allowed_filetypes)) {
            $error = 'Error: The file you attempted to upload is not allowed: ' . $ext . '<br />';
        }
        // Now check the filesize
        if (filesize($_FILES['app_file']['tmp_name']) > $max_filesize) {
            $error = 'Error: The file you attempted to upload is too large.<br />';
        }
        $upload_path = '../' . UPLOAD_PATH;
        // Check if we can upload to the specified path
        if (!is_writable($upload_path)) {
            $error = 'Error: You cannot upload to the directory ' . $upload_path . ', please CHMOD it to 777.<br />';
        }
        if ($error == '') {
            // Upload the file to your specified path.
            mkdir($upload_path . $folder_token, 0700);
            $file_path = $upload_path . $folder_token . '/app_bundle.ipa';
            if (move_uploaded_file($_FILES['app_file']['tmp_name'], $file_path)) {
                echo 'Your file upload was successful at: ' . $file_path . '<br />';

                $zip = new ZipArchive;
                //$res = $zip->open($file_path . $folder_token . '/');
                if (unzipApplication($upload_path . $folder_token . '/', 'app_bundle.ipa')) {
                    echo 'Unzip ok.<br>';

                    // We get the Info.plist of the app if it exists else <app_name>-Info.plist
                    $plistfile = Tools::getInfoPlistPath($folder_token);
                    echo 'Plist file: ' . $plistfile . '<br />';
                    $plistValue = file_get_contents($plistfile);

                    if (!empty($plistValue)) {
                        $plist = new CFPropertyList();
                        $plist->parse($plistValue, CFPropertyList::FORMAT_AUTO);
                        $plistData = $plist->toArray();

                        //TODO connect to apple and be sure a profile exists for this app.
                        //Create an application object and set basic data here
                        $entityManager = initDoctrine();

                        // Retrieve the developer connected (unique)
                        $developer = $entityManager->getRepository('Entities\Developer')->find($_SESSION['developerId']);

                        //verify if the application does not already exist (Ask to create an new version if it exists)
                        $application = $entityManager->getRepository('Entities\Application')->findOneBy(array('bundleId' => $plistData['CFBundleIdentifier']));
                        if ($application == NULL) {

                            $application = new Application();
                            $application->setDeveloper($developer);
                            $application->setBundleId($plistData['CFBundleIdentifier']);
                            $application->setBundleName($plistData['CFBundleName']);
                        }

                        //ensure version doesn't already exists.
                        //TODO version can be unspefied, handle this case.
                        $version = $entityManager->getRepository('Entities\Version')->findOneBy(array('version' => $plistData['CFBundleVersion']));
                        if ($version == NULL) {
                            $version = new Version();
                            $version->setVersion($plistData['CFBundleVersion']);
                            $version->setApplication($application);
                        } else {
                            // old version will be linked with new data folder using new token, 
                            // but delete old data folder before
                            $versionToken = $version->getToken();
                            Tools::deleteVersionDataFiles($versionToken);
                        }
                        $version->setName($plistData['CFBundleDisplayName']);
                        $version->setToken($folder_token);
                        $version->setDateUpload(new \DateTime('now'));
                        $entityManager->persist($application);
                        $entityManager->persist($version);
                        $entityManager->flush();
                        
                        //even if application already exists, update icon with last version uploaded.
                        //Get icon, if empty use 'Icon.png'	
                        if (isset($plistData['CFBundleIconFile']) && $plistData['CFBundleIconFile'] != 0) {
                            $iconFile = $plistData['CFBundleIconFile'];
                        } else {
                            $iconFile = 'Icon.png';
                        }
                        //uncrush icon
                        $pngPath = '../' . UPLOAD_PATH . $folder_token . '/app_bundle/Payload/'.$version->getName().'.app/'.$iconFile;
                        if (file_exists($pngPath)){
                            $png = new PngFile($pngPath);
                            $newPngPath = '../' . UPLOAD_PATH . $application->getBundleId().'.png';
                            $png->revertIphone($newPngPath);
                        }

                        echo '<a href="application_list.php">Go back to Application list</a>';
                    } else {
                        echo 'Error: Unable to read application plist file.<br />';
                    }
                } else {
                    echo 'Error: Unzip failed.<br />';
                }
            } else {
                echo 'Error: There was an error during the file upload.  Please try again.<br />';
            }
        } else {
            echo $error;
        }
    }
} else {
    echo '<p>Error: you can\'t call this page directly.</p>';
}

?>
                            
			</div>
		</div>
		
		<div class="column last">
			<div class="boxheader">
				<h2>Deployment steps</h2>
			</div>
			<div class="function">
				<h6>Send Invitations</h6>
				<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>
			</div>
			<hr>
			<div class="function">
				<h6>Tester get registered</h6>
				<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>
			</div>
			<hr>
			<div class="function">
				<h6>They install your app Over-The-Air</h6>				
				<p>Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p>
			</div>
		</div>	
		<div class="boxbottom"></div>
	</div>

</body>
</html>