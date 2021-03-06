<?php

namespace Sabre\DAV\Locks;

use Sabre\HTTP;
use Sabre\DAV;

require_once 'Sabre/DAV/AbstractServer.php';

class PluginTest extends DAV\AbstractServer {

    /**
     * @var Sabre\DAV\Locks\Plugin
     */
    protected $locksPlugin;

    function setUp() {

        parent::setUp();
        $locksBackend = new Backend\File(SABRE_TEMPDIR . '/locksdb');
        $locksPlugin = new Plugin($locksBackend);
        $this->server->addPlugin($locksPlugin);
        $this->locksPlugin = $locksPlugin;

    }

    function testGetFeatures() {

        $this->assertEquals(array(2),$this->locksPlugin->getFeatures());

    }

    function testGetHTTPMethods() {

        $this->assertEquals(array('LOCK','UNLOCK'),$this->locksPlugin->getHTTPMethods(''));

    }

    function testGetHTTPMethodsNoBackend() {

        $locksPlugin = new Plugin();
        $this->server->addPlugin($locksPlugin);
        $this->assertEquals(array(),$locksPlugin->getHTTPMethods(''));

    }

    function testLockNoBody() {

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('');
        $this->server->httpRequest = ($request);
        $this->server->exec();

        $this->assertEquals(array(
            'X-Sabre-Version' => DAV\Version::VERSION,
            'Content-Type' => 'application/xml; charset=utf-8',
            ),
            $this->response->headers
         );

        $this->assertEquals(400, $this->response->status);

    }

    function testLock() {

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(200, $this->response->status,'Got an incorrect status back. Response body: ' . $this->response->body);

        $body = preg_replace("/xmlns(:[A-Za-z0-9_])?=(\"|\')DAV:(\"|\')/","xmlns\\1=\"urn:DAV\"",$this->response->body);
        $xml = simplexml_load_string($body);
        $xml->registerXPathNamespace('d','urn:DAV');

        $elements = array(
            '/d:prop',
            '/d:prop/d:lockdiscovery',
            '/d:prop/d:lockdiscovery/d:activelock',
            '/d:prop/d:lockdiscovery/d:activelock/d:locktype',
            '/d:prop/d:lockdiscovery/d:activelock/d:lockroot',
            '/d:prop/d:lockdiscovery/d:activelock/d:lockroot/d:href',
            '/d:prop/d:lockdiscovery/d:activelock/d:locktype/d:write',
            '/d:prop/d:lockdiscovery/d:activelock/d:lockscope',
            '/d:prop/d:lockdiscovery/d:activelock/d:lockscope/d:exclusive',
            '/d:prop/d:lockdiscovery/d:activelock/d:depth',
            '/d:prop/d:lockdiscovery/d:activelock/d:owner',
            '/d:prop/d:lockdiscovery/d:activelock/d:timeout',
            '/d:prop/d:lockdiscovery/d:activelock/d:locktoken',
            '/d:prop/d:lockdiscovery/d:activelock/d:locktoken/d:href',
        );

        foreach($elements as $elem) {
            $data = $xml->xpath($elem);
            $this->assertEquals(1,count($data),'We expected 1 match for the xpath expression "' . $elem . '". ' . count($data) . ' were found. Full response body: ' . $this->response->body);
        }

        $depth = $xml->xpath('/d:prop/d:lockdiscovery/d:activelock/d:depth');
        $this->assertEquals('infinity',(string)$depth[0]);

        $token = $xml->xpath('/d:prop/d:lockdiscovery/d:activelock/d:locktoken/d:href');
        $this->assertEquals($this->response->headers['Lock-Token'],'<' . (string)$token[0] . '>','Token in response body didn\'t match token in response header.');

    }

    /**
     * @depends testLock
     */
    function testDoubleLock() {

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->response = new HTTP\ResponseMock();
        $this->server->httpResponse = $this->response;

        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);

        $this->assertEquals(423, $this->response->status, 'Full response: ' . $this->response->body);

    }

    /**
     * @depends testLock
     */
    function testLockRefresh() {

        $request = new HTTP\Request('LOCK', '/test.txt');
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $lockToken = $this->response->getHeader('Lock-Token');

        $this->response = new HTTP\ResponseMock();
        $this->server->httpResponse = $this->response;

        $request = new HTTP\Request('LOCK', '/test.txt', ['If' => '(' . $lockToken . ')' ]);
        $request->setBody('');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->getHeader('Content-Type'));

        $this->assertEquals(200, $this->response->status,'We received an incorrect status code. Full response body: ' . $this->response->getBody());

    }

    /**
     * @depends testLock
     */
    function testLockNoFile() {

        $serverVars = array(
            'REQUEST_URI'    => '/notfound.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(201, $this->response->status);

    }

    /**
     * @depends testLock
     */
    function testUnlockNoToken() {

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'UNLOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = ($request);
        $this->server->exec();

        $this->assertEquals(array(
            'X-Sabre-Version' => DAV\Version::VERSION,
            'Content-Type' => 'application/xml; charset=utf-8',
            ),
            $this->response->headers
         );

        $this->assertEquals(400, $this->response->status);

    }

    /**
     * @depends testLock
     */
    function testUnlockBadToken() {

        $serverVars = array(
            'REQUEST_URI'     => '/test.txt',
            'REQUEST_METHOD'  => 'UNLOCK',
            'HTTP_LOCK_TOKEN' => '<opaquelocktoken:blablabla>',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = ($request);
        $this->server->exec();

        $this->assertEquals(array(
            'X-Sabre-Version' => DAV\Version::VERSION,
            'Content-Type' => 'application/xml; charset=utf-8',
            ),
            $this->response->headers
         );

        $this->assertEquals(409, $this->response->status, 'Got an incorrect status code. Full response body: ' . $this->response->body);

    }

    /**
     * @depends testLock
     */
    function testLockPutNoToken() {

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(200, $this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'PUT',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('newbody');
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(423, $this->response->status);

    }

    /**
     * @depends testLock
     */
    function testUnlock() {

        $request = HTTP\Sapi::createFromServerArray([
            'REQUEST_URI' => '/test.txt',
            'REQUEST_METHOD' => 'LOCK',
        ]);
        $this->server->httpRequest = $request;

        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->invokeMethod($request, $this->server->httpResponse);
        $lockToken = $this->server->httpResponse->headers['Lock-Token'];

        $serverVars = array(
            'HTTP_LOCK_TOKEN' => $lockToken,
            'REQUEST_URI' => '/test.txt',
            'REQUEST_METHOD' => 'UNLOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = $request;
        $this->server->httpResponse = new HTTP\ResponseMock();
        $this->server->invokeMethod($request, $this->server->httpResponse);

        $this->assertEquals(204,$this->server->httpResponse->status,'Got an incorrect status code. Full response body: ' . $this->response->body);
        $this->assertEquals(array(
            'X-Sabre-Version' => DAV\Version::VERSION,
            'Content-Length' => '0',
            ),
            $this->server->httpResponse->headers
         );


    }

    /**
     * @depends testLock
     */
    function testUnlockWindowsBug() {

        $request = HTTP\Sapi::createFromServerArray([
            'REQUEST_URI' => '/test.txt',
            'REQUEST_METHOD' => 'LOCK',
        ]);
        $this->server->httpRequest = $request;

        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->invokeMethod($request, $this->server->httpResponse);
        $lockToken = $this->server->httpResponse->headers['Lock-Token'];

        // See Issue 123
        $lockToken = trim($lockToken,'<>');

        $serverVars = array(
            'HTTP_LOCK_TOKEN' => $lockToken,
            'REQUEST_URI' => '/test.txt',
            'REQUEST_METHOD' => 'UNLOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = $request;
        $this->server->httpResponse = new HTTP\ResponseMock();
        $this->server->invokeMethod($request, $this->server->httpResponse);

        $this->assertEquals(204, $this->server->httpResponse->status,'Got an incorrect status code. Full response body: ' . $this->response->body);
        $this->assertEquals(array(
            'X-Sabre-Version' => DAV\Version::VERSION,
            'Content-Length' => '0',
            ),
            $this->server->httpResponse->headers
         );


    }

    /**
     * @depends testLock
     */
    function testLockRetainOwner() {

        $request = HTTP\Sapi::createFromServerArray([
            'REQUEST_URI' => '/test.txt',
            'REQUEST_METHOD' => 'LOCK',
        ]);
        $this->server->httpRequest = $request;

        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>Evert</D:owner>
</D:lockinfo>');

        $this->server->invokeMethod($request, $this->server->httpResponse);
        $lockToken = $this->server->httpResponse->headers['Lock-Token'];

        $locks = $this->locksPlugin->getLocks('test.txt');
        $this->assertEquals(1,count($locks));
        $this->assertEquals('Evert',$locks[0]->owner);


    }

    /**
     * @depends testLock
     */
    function testLockPutBadToken() {

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(200, $this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'PUT',
            'HTTP_IF' => '(<opaquelocktoken:token1>)',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('newbody');
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        // $this->assertEquals('412 Precondition failed',$this->response->status);
        $this->assertEquals(423, $this->response->status);

    }

    /**
     * @depends testLock
     */
    function testLockDeleteParent() {

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(200, $this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/dir',
            'REQUEST_METHOD' => 'DELETE',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals(423, $this->response->status);
        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);

    }
    /**
     * @depends testLock
     */
    function testLockDeleteSucceed() {

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(200, $this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'DELETE',
            'HTTP_IF' => '(' . $this->response->headers['Lock-Token'] . ')',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals(204, $this->response->status);
        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);

    }

    /**
     * @depends testLock
     */
    function testLockCopyLockSource() {

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(200, $this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'COPY',
            'HTTP_DESTINATION' => '/dir/child2.txt',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals(201, $this->response->status,'Copy must succeed if only the source is locked, but not the destination');
        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);

    }
    /**
     * @depends testLock
     */
    function testLockCopyLockDestination() {

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child2.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(201, $this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'COPY',
            'HTTP_DESTINATION' => '/dir/child2.txt',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals(423, $this->response->status,'Copy must succeed if only the source is locked, but not the destination');
        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);

    }

    /**
     * @depends testLock
     */
    function testLockMoveLockSourceLocked() {

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(200, $this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'MOVE',
            'HTTP_DESTINATION' => '/dir/child2.txt',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals(423, $this->response->status,'Copy must succeed if only the source is locked, but not the destination');
        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);

    }

    /**
     * @depends testLock
     */
    function testLockMoveLockSourceSucceed() {

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(200, $this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'MOVE',
            'HTTP_DESTINATION' => '/dir/child2.txt',
            'HTTP_IF' => '(' . $this->response->headers['Lock-Token'] . ')',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals(201, $this->response->status,'A valid lock-token was provided for the source, so this MOVE operation must succeed. Full response body: ' . $this->response->body);

    }

    /**
     * @depends testLock
     */
    function testLockMoveLockDestination() {

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child2.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(201, $this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'MOVE',
            'HTTP_DESTINATION' => '/dir/child2.txt',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals(423, $this->response->status,'Copy must succeed if only the source is locked, but not the destination');
        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);

    }
    /**
     * @depends testLock
     */
    function testLockMoveLockParent() {

        $serverVars = array(
            'REQUEST_URI'    => '/dir',
            'REQUEST_METHOD' => 'LOCK',
            'HTTP_DEPTH' => 'infinite',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(200,$this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/dir/child.txt',
            'REQUEST_METHOD' => 'MOVE',
            'HTTP_DESTINATION' => '/dir/child2.txt',
            'HTTP_IF' => '</dir> (' . $this->response->headers['Lock-Token'] . ')',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals(201, $this->response->status,'We locked the parent of both the source and destination, but the move didn\'t succeed.');
        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);

    }

    /**
     * @depends testLock
     */
    function testLockPutGoodToken() {

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'LOCK',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('<?xml version="1.0"?>
<D:lockinfo xmlns:D="DAV:">
    <D:lockscope><D:exclusive/></D:lockscope>
    <D:locktype><D:write/></D:locktype>
    <D:owner>
        <D:href>http://example.org/~ejw/contact.html</D:href>
    </D:owner>
</D:lockinfo>');

        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(200, $this->response->status);

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'PUT',
            'HTTP_IF' => '('.$this->response->headers['Lock-Token'].')',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('newbody');
        $this->server->httpRequest = $request;
        $this->server->exec();

        $this->assertEquals('application/xml; charset=utf-8',$this->response->headers['Content-Type']);
        $this->assertTrue(preg_match('/^<opaquelocktoken:(.*)>$/',$this->response->headers['Lock-Token'])===1,'We did not get a valid Locktoken back (' . $this->response->headers['Lock-Token'] . ')');

        $this->assertEquals(204, $this->response->status);

    }

    function testPutWithIncorrectETag() {

        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'PUT',
            'HTTP_IF' => '(["etag1"])',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('newbody');
        $this->server->httpRequest = $request;
        $this->server->exec();
        $this->assertEquals(412, $this->response->status);

    }

    /**
     * @depends testPutWithIncorrectETag
     */
    function testPutWithCorrectETag() {

        // We need an etag-enabled file node.
        $tree = new DAV\ObjectTree(new DAV\FSExt\Directory(SABRE_TEMPDIR));
        $this->server->tree = $tree;

        $etag = md5(file_get_contents(SABRE_TEMPDIR . '/test.txt'));
        $serverVars = array(
            'REQUEST_URI'    => '/test.txt',
            'REQUEST_METHOD' => 'PUT',
            'HTTP_IF' => '(["'.$etag.'"])',
        );

        $request = HTTP\Sapi::createFromServerArray($serverVars);
        $request->setBody('newbody');
        $this->server->httpRequest = $request;
        $this->server->exec();
        $this->assertEquals(204, $this->response->status, 'Incorrect status received. Full response body:' . $this->response->body);

    }

    function testDeleteWithETagOnCollection() {

        $serverVars = array(
            'REQUEST_URI'    => '/dir',
            'REQUEST_METHOD' => 'DELETE',
            'HTTP_IF' => '(["etag1"])',
        );
        $request = HTTP\Sapi::createFromServerArray($serverVars);

        $this->server->httpRequest = $request;
        $this->server->exec();
        $this->assertEquals(412, $this->response->status);

    }

    function testGetTimeoutHeader() {

        $request = HTTP\Sapi::createFromServerArray(array(
            'HTTP_TIMEOUT' => 'second-100',
        ));

        $this->server->httpRequest = $request;
        $this->assertEquals(100, $this->locksPlugin->getTimeoutHeader());

    }

    function testGetTimeoutHeaderTwoItems() {

        $request = HTTP\Sapi::createFromServerArray(array(
            'HTTP_TIMEOUT' => 'second-5, infinite',
        ));

        $this->server->httpRequest = $request;
        $this->assertEquals(5, $this->locksPlugin->getTimeoutHeader());

    }

    function testGetTimeoutHeaderInfinite() {

        $request = HTTP\Sapi::createFromServerArray(array(
            'HTTP_TIMEOUT' => 'infinite, second-5',
        ));

        $this->server->httpRequest = $request;
        $this->assertEquals(LockInfo::TIMEOUT_INFINITE, $this->locksPlugin->getTimeoutHeader());

    }

    /**
     * @expectedException Sabre\DAV\Exception\BadRequest
     */
    function testGetTimeoutHeaderInvalid() {

        $request = HTTP\Sapi::createFromServerArray(array(
            'HTTP_TIMEOUT' => 'yourmom',
        ));

        $this->server->httpRequest = $request;
        $this->locksPlugin->getTimeoutHeader();

    }


}
