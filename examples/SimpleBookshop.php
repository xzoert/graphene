<?php

/**
Simple bookshop example. 
The createData function should illustrate most of the basic write procedures.
The below set of queries is aimed to illustrate the query language.

Have a look as well at the generated definition files....

- Max Jacob 02 2015
*/


require_once 'init.php';


/**
Creation of the test data set.
*/
function createData($db) 
{

	echo "Creating data set.... ";
	
	$db->begin();
	
	$john=$db->Person();
	$john->firstName="John";
	$john->lastName="Smith";
	
	$bookshop=$db->Bookshop();
	$bookshop->owner=$john;
	$bookshop->name="John's bookshop";
	$bookshop->openSince=new DateTime('1986-05-13');
	
	$joyce=$db->Person(array('firstName'=>'James','lastName'=>'Joyce','isFamous'=>1));
	$fwake=$db->Book(array('title:en'=>'Finnegans wake','author'=>$joyce));
	$fwake->set('title:it','La veglia di Finnegan');
	
	
	$johnsbook=$db->Book(array('title'=>'How to run a bookshop','author'=>$john));
	
	$bookshop->books->add($fwake);
	$bookshop->books->add($johnsbook);
	
	echo "done.",PHP_EOL,PHP_EOL;

	// not worth committing... I return
	
	return $bookshop;
}




try {
	
	
	$db->unfreeze();

	$t0=microtime(true);
	echo PHP_EOL,PHP_EOL,'####################### SIMPLE BOOKSHOP EXAMPLE #########################',PHP_EOL,PHP_EOL;

	
	
	
	$bookshop=$db->Bookshop->getBy("name","John's bookshop");
	if (!$bookshop) {
		$bookshop=createData($db);
	}

	
	echo $bookshop->name,' is open since ',$bookshop->openSince->format('M Y'),'.',PHP_EOL,PHP_EOL;	
	
	echo "All books in John's bookshop whose title begins with 'F':",PHP_EOL;
	foreach ($bookshop->books->select("title like 'f%'") as $book) {
		echo "\t",$book->getTr('title','en_Us'),PHP_EOL;
	}
	echo PHP_EOL;	
	
	
	echo "All authors of any book sold in ",$bookshop->name,PHP_EOL;
	foreach ($db->select("Book#book and Bookshop#bs=? and #bs.books=#book and #book.author=#x",$bookshop) as $author) {
		echo "\t",$author->firstName,' ',$author->lastName,PHP_EOL;
	}
	echo PHP_EOL;	
	
	
	echo "All bookshop owners whose bookshop sells a book written by their owns as well as at least one book written by a famous author....:",PHP_EOL;
	foreach ($db->select("Person#x and Bookshop#bookshop and #bookshop.owner=#x and #bookshop.books#book and #book.author=#x and #bookshop.books#book2 and #book2.author.isFamous=1") as $node) {
		echo "\t",$node->firstName," ",$node->lastName,PHP_EOL;
	}
	echo PHP_EOL;	
	
	
	$db->close();	
		
	echo PHP_EOL,PHP_EOL,'##################### SIMPLE BOOKSHOP EXAMPLE END #######################',PHP_EOL,PHP_EOL;
	
	echo 'took: ',(microtime(true)-$t0),PHP_EOL,PHP_EOL;
	
	if (isset($_SERVER['HTTP_HOST'])) echo '</pre>';
	
	
} catch (Exception $e) {
	if( $db ) $db->rollback();
	throw $e;
}



