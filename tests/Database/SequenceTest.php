<?php

namespace Yajra\Oci8\Tests\Database;

use Mockery as m;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Yajra\Oci8\Oci8Connection;
use Yajra\Oci8\Query\Grammars\OracleGrammar;
use Yajra\Oci8\Schema\Sequence;

class SequenceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    #[Test]
    public function it_will_create_sequence()
    {
        $connection = $this->getConnection();
        $sequence = new Sequence($connection);
        $connection->shouldReceive('getConfig')->andReturn('');
        $connection->shouldReceive('getConfig')->with('server_version')->andReturn(getenv('SERVER_VERSION') ? getenv('SERVER_VERSION') : '11g');
        $connection->shouldReceive('statement')->andReturn(true);
        $connection->shouldReceive('getSchemaPrefix');

        $grammar = m::mock(OracleGrammar::class);
        $grammar->shouldReceive('wrap')->andReturn('users_id_seq');

        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar);

        $success = $sequence->create('users_id_seq');
        $this->assertTrue($success);
    }

    protected function getConnection()
    {
        return m::mock(Oci8Connection::class);
    }

    #[Test]
    public function it_will_drop_sequence()
    {
        $sequence = m::mock(Sequence::class);
        $sequence->shouldReceive('drop')->andReturn(true);
        $sequence->shouldReceive('exists')->andReturn(false);
        $success = $sequence->drop('users_id_seq');
        $this->assertTrue($success);
    }

    public function test_next_value_rejects_an_integer_outside_the_php_range(): void
    {
        $statement = m::mock(PDOStatement::class);
        $statement->shouldReceive('setFetchMode')->once()->with(PDO::FETCH_OBJ);
        $statement->shouldReceive('execute')->once()->andReturn(true);
        $statement->shouldReceive('fetchAll')->once()->andReturn([(object) ['id' => PHP_INT_MAX.'0']]);

        $pdo = m::mock(PDO::class);
        $pdo->shouldReceive('prepare')->once()->with('SELECT "USERS_ID_SEQ".NEXTVAL as "id" FROM DUAL')->andReturn($statement);
        $sequence = new Sequence(new Oci8Connection($pdo));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Oracle did not return an integer sequence value.');

        $sequence->nextValue('users_id_seq');
    }
}
