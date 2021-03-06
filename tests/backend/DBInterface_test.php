<?php 
/*
 * Copyright (C) 2015 Marcel Bollmann <bollmann@linguistics.rub.de>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of
 * this software and associated documentation files (the "Software"), to deal in
 * the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS
 * FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 * COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER
 * IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
 * CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */ ?>
<?php
require_once"DB_fixture.php";
require_once"data/test_data.php";
require_once"mocks/LocaleHandler_mock.php";

require_once"{$GLOBALS['CORA_WEB_DIR']}/lib/connect.php";

/** Tests for DBInterface class
 *
 *  02/2013 Florian Petran
 *
 * DBInterface abstracts all operations that relate to the database.
 *
 * TODO
 * tests for:
 *      getErrorTypes()
 *      getUserData($user, $pw)
 *      changePassword($uid, $password)
 *      changeProjectUsers($pid, $userlist)
 *      getTextIdForToken($tok_id)
 *  coverage for:
 *      getLines($fid, $start, $lim);
 *      saveLines($fid, $lastedited, $lines);
 */
class Cora_Tests_DBInterface_test extends Cora_Tests_Old_DbTestCase {
    protected $dbi;
    protected $backupGlobalsBlacklist = array('_SESSION');
    protected $expected;

    protected function setUp() {
      $dbinfo = array(
        'HOST' => $GLOBALS["DB_HOST"],
        'USER' => $GLOBALS["DB_USER"],
        'PASSWORD' => $GLOBALS["DB_PASSWD"],
        'DBNAME' => $GLOBALS["DB_DBNAME"]
      );
      $this->dbi = new DBInterface($dbinfo, new MockLocaleHandler());
      $this->expected = get_DBInterface_expected();
      parent::setUp();
    }

    public function testGetUser() {
        $this->assertEquals($this->expected["users"]["system"],
                            $this->dbi->getUserById(1));
        $this->assertEquals($this->expected["users"]["test"],
                            $this->dbi->getUserById(5));

        $this->assertEquals($this->expected["users"]["system"],
                            $this->dbi->getUserByName('system'));
        $this->assertEquals($this->expected["users"]["test"],
                            $this->dbi->getUserByName('test'));


        $this->assertEquals(1, $this->dbi->getUserIDFromName('system'));
        $this->assertEquals(5, $this->dbi->getUserIDFromName('test'));

        $this->assertEquals(array(array_merge($this->expected["users"]["bollmann"],
					      array("active" => '0', "opened_text" => '3', "email" => null, "comment" => null)),
				  array_merge($this->expected["users"]["test"],
					      array("active" => '0', "opened_text" => null, "email" => null, "comment" => null))),
			    $this->dbi->getUserList(30));

        // Calling getUserData() updates the password hash if necessary,
        // which modifies 'lastactive', so put this test last
        $result = $this->dbi->getUserData("test", "test");
        $this->assertEquals(array('id' => '5',
                                  'name' => 'test',
                                  'admin' => '0',
                                  'lastactive' => '2013-01-22 15:38:32',
                                  'password' => '68358d5d9cbbf39fe571ba41f26524b6'),
                            $result);
    }

    public function testUserActions() {
        // create user
        // creating a user that already exists should fail
        $this->assertFalse($this->dbi->createUser("test", "blabla", "0"));

        $this->dbi->createUser("anselm", "blabla", "0");
        $expected = $this->createXMLDataSet(__DIR__ . "/data/created_user.xml");

        // TODO password hash breaks table equality, idk why
        $this->assertTablesEqual($expected->getTable("users"),
                                 $this->getConnection()->createQueryTable("users",
                                    "SELECT id,name,admin FROM users WHERE name='anselm';"));

        // Passwords cannot be asserted against the DB any longer since the
        // hashing algorithm is unknown (i.e., it uses the algorithm currently
        // considered as "best" by PHP, which could change between versions)
        // and also uses dynamically generated salts.
        $this->dbi->changePassword(5, "password");  // user "test"
        $this->assertTrue($this->dbi->verifyPassword(
                              "password",  // old hash supported for compatibility
                              "1619d7adc23f4f633f11014d2f22b7d8")
        );
        $this->assertTrue($this->dbi->verifyPassword(
                              "password",
                              $this->getConnection()->createQueryTable("users",
                              "SELECT password FROM users WHERE name='test'")->getValue(0, 'password'))
        );

        $this->dbi->deleteUser(6);  // user "anselm"
        $this->assertEquals(0, $this->getConnection()->createQueryTable("users",
                               "SELECT id,name,admin FROM users WHERE name='anselm';")->getRowCount());

        $this->dbi->toggleAdminStatus(5);
        $this->assertEquals(1, $this->getConnection()->createQueryTable("testuser",
                               "SELECT admin FROM users WHERE name='test';")->getValue(0, "admin"));

        $this->dbi->toggleAdminStatus(5);
        $this->assertEquals(0, $this->getConnection()->createQueryTable("testuser",
                               "SELECT admin FROM users WHERE name='test';")->getValue(0, "admin"));

        $this->assertTrue($this->dbi->isAllowedToOpenFile("3", "bollmann"));
        $this->assertFalse($this->dbi->isAllowedToOpenFile("3", "test"));
        $this->assertTrue($this->dbi->isAllowedToDeleteFile("3", "bollmann"));
        $this->assertFalse($this->dbi->isAllowedToDeleteFile("3", "test"));
    }

    public function testUserSettings() {
        $this->assertEquals($this->expected["settings"]["test"],
                            $this->dbi->getUserSettings("test"));

        $this->dbi->setUserSettings("test", "50", "3");
        $this->assertEquals(50,
            $this->getConnection()->createQueryTable("settings",
            "SELECT lines_per_page FROM users WHERE name='test';")->getValue(0, "lines_per_page"));
        $this->assertEquals(3,
            $this->getConnection()->createQueryTable("settings",
            "SELECT lines_context FROM users WHERE name='test';")->getValue(0, "lines_context"));

        $this->dbi->setUserSetting("test", "columns_order", "7/6,6/7");
        $this->assertEquals("7/6,6/7",
            $this->getConnection()->createQueryTable("settings",
            "SELECT columns_order FROM users WHERE name='test';")->getValue(0, "columns_order"));
        $this->assertFalse($this->dbi->setUserSetting("test", "invalid_field", "somevalue"));

        // isAllowedToDeleteFile($fid, $user)
        // isAllowedToOpenFile($fid, $user)
    }

    public function testTextQuery() {
      //$actual = $this->dbi->queryForMetadata("sigle", "t1");
      //$this->assertEquals(array_merge($this->expected["texts"]["t1"],
      //				$this->expected["texts"]["header_fullfile"]),
      //		    $actual);
      //$actual = $this->dbi->queryForMetadata("fullname", "yet another dummy");
      //$this->assertEquals(array_merge($this->expected["texts"]["t2"],
      //				$this->expected["texts"]["header_fullfile"]),
      //		    $actual);

        $this->assertEquals(array('file_id' => '3', 'file_name' => 'test-dummy'),
                            $this->dbi->getLockedFiles("bollmann"));

        $getfiles_expected = array();
        // getFiles also gives lots of names for display purposes
        foreach (array("t1","t2","t3") as $textkey) {
            $getfiles_expected[] = array_merge($this->expected["texts"][$textkey],
                                               $this->expected["texts_extended"][$textkey]);
        }
        $this->assertEquals($getfiles_expected, $this->dbi->getFiles());
        $this->assertEquals($getfiles_expected,
                            $this->dbi->getFilesForUser("bollmann"));

        $this->dbi->performSaveLines("3", array(), "2");
        $this->assertEquals("2",
            $this->getConnection()->createQueryTable("currentpos",
            "SELECT currentmod_id FROM text WHERE id=3;")->getValue(0, "currentmod_id"));
    }

    public function testLockUnlock() {
        // locking a file that is already locked returns info on the lock
        $lock_result = $this->dbi->lockFile("3", "test");
        $this->assertEquals(array("success" => false,
                                  "lock" => array("locked_since" => "2013-02-05 13:00:40",
                                                  "locked_by" => "bollmann")),
                            $lock_result);
        // check if the database still has the lock belonging to bollmann
        $this->assertEquals("3",
            $this->getConnection()->createQueryTable("testlock",
            "SELECT user_id FROM locks WHERE text_id=3;")->getValue(0, "user_id"));


        // test force unlock with specification of user name
        $this->dbi->unlockFile("3", "bollmann", "true");
        $this->assertEquals("0",
            $this->getConnection()->createQueryTable("locks",
            "SELECT * FROM locks WHERE text_id=3;")->getRowCount());

        // test locking a new file
        $lock_result = $this->dbi->lockFile("4", "test");
        $this->assertEquals(array("success" => true, "lockCounts" => 0),
                            $lock_result);
        $this->assertEquals("4",
            $this->getConnection()->createQueryTable("testlock",
            "SELECT text_id FROM locks WHERE user_id=5;")->getValue(0, "text_id"));

        // test unlocking with force=false
        // fake login as bollmann
        $_SESSION["user_id"] = "3";
        // this should fail
        $lock_result = $this->dbi->unlockFile("4");
        $this->assertEquals("1",
            $this->getConnection()->createQueryTable("testlock",
            "SELECT * FROM locks WHERE text_id=4;")->getRowCount());

        // this should succeed
        $lock_result = $this->dbi->unlockFile("4", "test");
        $this->assertEquals("0",
            $this->getConnection()->createQueryTable("testlock",
            "SELECT * FROM locks WHERE text_id=4;")->getRowCount());
    }

    public function testOpenText() {
        // test file opening
        $_SESSION["user"] = "bollmann";
        $_SESSION["user_id"] = "3";
        $this->assertEquals(
            array("lastEditedRow" => -1,
                  "data" => array_merge($this->expected["texts"]["t1_reduced"],
                                        array(
					      "taggers" => array(),
					      "tagsets" => array(
						 $this->expected["tagsets"]["ts1"],
						 $this->expected["tagsets"]["ts2"],
						 $this->expected["tagsets"]["ts3"],
						 $this->expected["tagsets"]["ts4"],
								 ),
                                              "idlist" => array(
                                                 0 => '1', 1 => '2', 2 => '3',
                                                 3 => '4', 4 => '5', 5 => '6',
                                                 6 => '7', 7 => '8', 8 => '9'
                                              ))),
                  "success" => true),
            $this->dbi->openFile("3", "bollmann")
        );

        $_SESSION["user"] = "test";
        $_SESSION["user_id"] = "5";
        $this->assertEquals(
            array("lastEditedRow" => 1,
                  "data" => array_merge($this->expected["texts"]["t2_reduced"],
                                        array(
					      "taggers" => array(),
					      "tagsets" => array(
						 $this->expected["tagsets"]["ts1"]
								 ),
                                              "idlist" => array(
                                                 0 => '13', 1 => '14'
                                              ))),
                  "success" => true),
            $this->dbi->openFile("4")
        );

        // opening a file that's already opened by someone else must fail
        $this->assertEquals(array("success" => false,
                                  "errors" => array("lock failed")),
                            $this->dbi->openFile("3"));
    }
    public function testGetLines() {
        $lines_expected = $this->expected["lines"];

        $this->assertEquals($lines_expected,
                            $this->dbi->getLines("3", "0", "10"));

        $lines_chunk = array_chunk($lines_expected, 3);
        $this->assertEquals($lines_chunk[0],
                            $this->dbi->getLines("3", "0", "3"));
        $this->assertEquals($lines_chunk[1],
                            $this->dbi->getLines("3", "3", "3"));

        // querying over the maximum lines number gives an empty array
        $this->assertEquals(array(),
                            $this->dbi->getLines("3", "500", "10"));
        // querying a file that has no tokens gives an empty array as well
        $this->assertEquals(array(),
                            $this->dbi->getLines("5", "0", "10"));

        $this->assertEquals("9", $this->dbi->getMaxLinesNo("3"));
        $this->assertEquals("0", $this->dbi->getMaxLinesNo("5"));
        $this->assertEquals("0", $this->dbi->getMaxLinesNo("512"));
    }

    public function testProjects() {
        $this->assertEquals(array(
                                array('id' => '1', 'name' => 'Default-Gruppe')
                            ),
                            $this->dbi->getProjects());
        $this->assertEquals(array(array('project_id' => '1', 'username' => 'bollmann')),
                            $this->dbi->getProjectUsers());

        $this->assertEquals(array(array('id' => '1', 'name' => 'Default-Gruppe')),
                            $this->dbi->getProjectsForUser("bollmann"));

        $this->dbi->createProject("testproject");
        $expected = $this->createXMLDataSet(__DIR__ . "/data/created_project.xml");

        $this->assertTablesEqual($expected->getTable("project"),
            $this->getConnection()->createQueryTable("project",
            "SELECT * FROM project WHERE name='testproject'"));

        $this->assertTrue($this->dbi->deleteProject("2"));
        $this->assertEquals("0",
            $this->getConnection()->createQueryTable("project",
            "SELECT * FROM project WHERE id=2")->getRowCount());

        // deleting a project that has users attached should fail
        // but that test is further down in the FK aware class

        $users = array("5");
        $this->dbi->changeProjectUsers("1", $users);
        $this->assertEquals("1",
            $this->getConnection()->createQueryTable("projectusers",
            "SELECT * FROM user2project WHERE user_id=5 AND project_id=1")->getRowCount());
    }

    public function testSaveLines() {
        //saveLines($fid, $lastedited, $lines);
        $lines = array(
                    array('id' => '2',
                          'anno_pos' => 'PPOSS',
                          'anno_morph' => 'Fem.Nom.Sg',
                          'comment' => 'testcomment'),
                    array('id' => '3',
                          'anno_pos' => 'VVFIN',
                          'anno_morph' => '3.Pl.Past.Konj',
                          'comment' => ''),
                    array('id' => '4',
                          'anno_pos' => null),
                    array('id' => '5',
                          'anno_pos' => 'VVPP',
                          'anno_lemma' => 'newlemma',
                          'comment' => ''),
                    array('id' => '6',
                          'anno_norm' => 'newnorm'),
                    array('id' => '7',
                          'anno_pos' => 'NN',
                          'anno_morph' => 'Neut.Dat.Pl',
                          'general_error' => false,
                          'anno_lemma' => null),
                    array('id' => '8',
                          'anno_norm' => 'bla',
                          'anno_pos' => '',
                          'general_error' => true),
                    array('id' => '9',
                          'anno_morph' => 'Neut.Nom.Sg',
                          'anno_lemma' => 'blatest',
                          'anno_norm' => "")
                );
        $this->assertEquals("lock failed",
            $this->dbi->saveLines("3", "9", $lines, "test"));

        $result = $this->dbi->saveLines("3", "9", $lines, "bollmann");
        $this->assertFalse($result);
        $expected = $this->createXMLDataset(__DIR__ . "/data/saved_lines.xml");
	$this->assertTablesEqual($expected->getTable("tag_suggestion"),
            $this->getConnection()->createQueryTable("tag_suggestion",
             "SELECT id,selected,source,tag_id,mod_id "
	     ."FROM tag_suggestion WHERE mod_id > 2 and mod_id < 9"));
        $this->assertTablesEqual($expected->getTable("tag"),
            $this->getConnection()->createQueryTable("tag",
            "SELECT * FROM tag WHERE id > 509"));
        $this->assertTablesEqual($expected->getTable("mod2error"),
            $this->getConnection()->createQueryTable("mod2error",
            "SELECT * FROM mod2error WHERE mod_id IN (7, 8, 9)"));

        $this->assertTablesEqual($expected->getTable("comment"),
            $this->getConnection()->createQueryTable("comment",
            "SELECT * FROM comment"));

        $lines = array(array('id'=>'14'));
	$this->setExpectedException('DocumentAccessViolation');
        $result = $this->dbi->saveLines("3", "1", $lines, "bollmann");
    }

    public function testTags() {
        $tagsets = array(
            array('id' => '1',
                  'class' => 'pos',
                  'shortname' => '1',
                  'longname' => 'ImportTest',
                  'set_type' => 'closed',
                  'settings' => ''),
            array('id' => '3',
                  'class' => 'lemma',
                  'shortname' => '3',
                  'longname' => 'LemmaTest',
                  'set_type' => 'open',
                  'settings' => ''),
            array('id' => '2',
                  'class' => 'norm',
                  'shortname' => '2',
                  'longname' => 'NormTest',
                  'set_type' => 'open',
                  'settings' => ''),
            array('id' => '4',
                  'class' => 'comment',
                  'shortname' => '4',
                  'longname' => 'Comment',
                  'set_type' => 'open',
                  'settings' => '')
        );

        $this->assertEquals($tagsets,
                            $this->dbi->getTagsets(null),
                            "Reported tagsets",
                            $delta = 0.0, $maxDepth = 10, $canonicalize = true);
        $this->assertEquals(array($tagsets[0]),
                            $this->dbi->getTagsets("pos"));

        // getTagsetsForFile returns a slightly different array,
        // so we can't use the expected array from above.
        $tagsets_gtff = array(
            array('id' => '1',
                  'class' => 'pos',
                  'name' => 'ImportTest',
                  'set_type' => 'closed',
                  'settings' => ''),
            array('id' => '2',
                  'class' => 'norm',
                  'name' => 'NormTest',
                  'set_type' => 'open',
                  'settings' => ''),
            array('id' => '3',
                  'class' => 'lemma',
                  'name' => 'LemmaTest',
                  'set_type' => 'open',
                  'settings' => ''),
            array('id' => '4',
                  'class' => 'comment',
                  'name' => 'Comment',
                  'set_type' => 'open',
                  'settings' => '')
        );

        $this->assertEquals($tagsets_gtff,
                            $this->dbi->getTagsetsForFile("3"),
                            "Reported tagsets for file 3",
                            $delta = 0.0, $maxDepth = 10, $canonicalize = true);

        $lemma_tagset = array(array('id' => '512',
                                    'value' => 'deletedlemma',
                                    'needs_revision' => '1'),
                              array('id' => '511',
                                    'value' => 'lemma',
                                    'needs_revision' => '0'));

        // default value for limit is 'none', but actually other
        // strings would work too
        $this->assertEquals($lemma_tagset,
                            $this->dbi->getTagset("3"),
                            "Lemma tagset",
                            $delta = 0.0, $maxDepth = 10, $canonicalize = true);
        // cf. <http://stackoverflow.com/a/28189403> ------^

        $this->assertEquals(array('lemma' => '511',
                                  'deletedlemma' => '512'),
                            $this->dbi->getTagsetByValue("3"));
        //$this->dbi->importTagList($taglist, $name);
    }

    public function testEditToken() {
        // $this->editToken($textid, $tokenid, $toktrans, $converted);
        $this->assertEquals(array("success" => false,
                                  "errors" => array("TranscriptionError.lineBreakDangling")),
                            $this->dbi->editToken("4", "6", "neutest", "test neu", "3"));

        $actual = $this->dbi->editToken("3", "3",
                                  "neutest", array("dipl_trans" => array("testneu"),
                                                   "dipl_utf" => array("testneu"),
                                                   "mod_trans" => array("testneu"),
                                                   "mod_ascii" => array("testneu"),
                                                   "mod_utf" => array("testneu"),
                                                   "dipl_breaks" => array("0")), "3"
        );
        $this->assertEquals(array("success" => true,
                                  "oldmodcount" => 1,
                                  "newmodcount" => 1),
                            $actual);
        $expected = $this->createXMLDataset(__DIR__ . "/data/token.xml");
        $this->assertTablesEqual($expected->getTable("edited_token"),
            $this->getConnection()->createQueryTable("edited_token",
            "SELECT * FROM token WHERE id=3"));
        $this->assertTablesEqual($expected->getTable("edited_dipl"),
            $this->getConnection()->createQueryTable("edited_dipl",
            "SELECT * FROM dipl WHERE tok_id=3"));
        $this->assertTablesEqual($expected->getTable("edited_modern"),
            $this->getConnection()->createQueryTable("edited_modern",
            "SELECT * FROM modern WHERE tok_id=3"));
    }

    public function testAddToken() {
        $this->assertEquals(array("success" => true,
                                  "newmodcount" => 1),
            $this->dbi->addToken("3", "3", "testadd",
                                 array("dipl_trans" => array("testadd"),
                                       "dipl_utf" => array("testadd"),
                                       "mod_trans" => array("testadd"),
                                       "mod_ascii" => array("testadd"),
                                       "mod_utf" => array("testadd")), "3"
        ));

        $expected = $this->createXMLDataset(__DIR__ . "/data/token.xml");
        $actual = $this->getConnection()->createQueryTable("added_token",
                  "SELECT * FROM token");
        $this->assertEquals("7", $actual->getRowCount());
        $this->assertTablesEqual($expected->getTable("added_token"), $actual);

        $actual = $this->getConnection()->createQueryTable("added_dipl",
                  "SELECT * FROM dipl");
        $this->assertEquals("12", $actual->getRowCount());
        $this->assertTablesEqual($expected->getTable("added_dipl"), $actual);

        $actual = $this->getConnection()->createQueryTable("added_modern",
                  "SELECT * FROM modern");
        $this->assertEquals("12", $actual->getRowCount());
        $this->assertTablesEqual($expected->getTable("added_modern"), $actual);
    }

    public function testGetIDForToken() {
        for ($i = 1; $i <= 5; ++$i) {
            $this->assertEquals("3",
                $this->dbi->getTextIDForToken($i));
        }
        $this->assertEquals("4",
            $this->dbi->getTextIDForToken("6"));
    }

    /*
    public function testDeleteFile() {
        $this->dbi->deleteFile("3");
        // TODO of course it needs to test if the tokens, etc. are also
        // deleted, but cora relies on fk constraints for that, which are
        // ignored in our test db
        $this->assertEquals(0,
            $this->query("SELECT * FROM {$GLOBALS["DB_DBNAME"]}.text WHERE ID=3")->getRowCount());
    }

    public function testGetAllLines() {
        $this->assertEquals($this->expected["lines"],
                            $this->dbi->getAllLines("3"));
    }

    public function testGetSuggestions() {
        $actual = $this->dbi->getAllSuggestions("t1", "1");
        $this->assertEquals(array(
                            ),
                            $actual);
    }
     */


}
?>
