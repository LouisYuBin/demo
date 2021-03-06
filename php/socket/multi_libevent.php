<?php
/**
 * 多进程IO复用libevent
 * 同时处理多个连接
 * 惊群效应没处理
 */
class MultiProcessLibevent
{
    public $socket = false;
    public $master = array();
    public $onConnect = null;
    public $onMessage = null;
    public $onClose = null;
    public $process_num = 2;
    private $pids = array();
    public $receive = array();

    function __construct($host="0.0.0.0",$port=1215){
        //产生子进程分支
        $pid = pcntl_fork();
        if ($pid == -1) {
            die("could not fork"); //pcntl_fork返回-1标明创建子进程失败
        } else if ($pid) {
            exit(); //父进程中pcntl_fork返回创建的子进程进程号
        } else {
            // 子进程pcntl_fork返回的时0
        }
        // 从当前终端分离
        if (posix_setsid() == -1) {
            die("could not detach from terminal");
        }
        umask(0);
        $this->socket = stream_socket_server("tcp://".$host.":".$port, $errno, $errstr);
        if (!$this->socket) die($errstr."--".$errno);
        stream_set_blocking($this->socket,0);
        $id = (int)$this->socket;
        $this->master[$id] = $this->socket;
    }

    private function start_worker_process(){
        $pid = pcntl_fork();
        switch ($pid) {
            case -1:
                echo "fork error : {$pid} \r\n";
                exit;
            case 0:
                $base = event_base_new();
                $event = event_new();
                event_set($event, $this->socket, EV_READ | EV_PERSIST, array(__CLASS__, 'ev_accept'), $base);
                event_base_set($event, $base);
                event_add($event);
                echo   posix_getpid()." start run...\n";
                event_base_loop($base);
            default:
                $this->pids[$pid] = $pid;
                break;
        }
    }

    public function run(){

        for($i = 1; $i <= $this->process_num; $i++){
            $this->start_worker_process();
        }

        while(1){
            foreach ($this->pids as $i => $pid) {
                if($pid) {
                    $res = pcntl_waitpid($pid, $status,WNOHANG);

                    if ( $res == -1 || $res > 0 ){
                        $this->start_worker_process();
                        unset($this->pids[$pid]);
                    }
                }
            }
            sleep(1);
        }
    }

    public function ev_accept($socket, $flag, $base){
        $connection = @stream_socket_accept($socket);
        echo posix_getpid()." -- accepted " . stream_socket_get_name($connection,true) . "\n";
        if ( !$connection ){//惊群效应
            return;
        }
        stream_set_blocking($connection, 0);
        $id = (int)$connection;
        if($this->onConnect) {
            call_user_func($this->onConnect, $connection);
        }
        $buffer = event_buffer_new($connection, array(__CLASS__, 'ev_read'), array(__CLASS__, 'ev_write'), array(__CLASS__, 'ev_error'), $id);
        event_buffer_base_set($buffer, $base);
        event_buffer_timeout_set($buffer, 30, 30);
        event_buffer_watermark_set($buffer, EV_READ, 0, 0xffffff);
        event_buffer_priority_set($buffer, 10);
        event_buffer_enable($buffer, EV_READ | EV_PERSIST);
        $this->master[$id] = $connection;
        $this->buffer[$id] = $buffer;
        $this->receive[$id] = '';
    }

    function ev_read($buffer, $id)
    {
        while( 1 ) {
            $read = event_buffer_read($buffer, 3);
            if($read === '' || $read === false)
            {
                break;
            }
            $pos = strpos($read, "\n");
            if($pos === false)
            {
                $this->receive[$id] .= $read;
                //echo "received:".$read.";not all package,continue recdiveing\n";
            }else{
                $this->receive[$id] .= trim(substr ($read,0,$pos+1));
                $read = substr($read,$pos+1);
                if($this->onMessage)
                {
                    call_user_func($this->onMessage,$this->master[$id],$this->receive[$id]);
                }
                switch ( $this->receive[$id] ){
                    case "quit":
                        echo "client close conn\n";
                        if($this->onClose) {
                            call_user_func($this->onClose, $this->master[$id]);
                        }
                        fclose($this->master[$id]);
                        break;
                    default:
                        //echo "all package:\n";
                        //echo $this->receive[$id]."\n";
                        break;
                }
                $this->receive[$id]='';
            }
        }
    }

    function ev_write($buffer, $id)
    {
        echo "$id -- " ."\n";
    }

    function ev_error($buffer, $error, $id)
    {
        echo "ev_error - ".$error."\n";
    }

}
$server =  new MultiProcessLibevent();

$server->onConnect = function($conn){
    echo "onConnect -- accepted " . stream_socket_get_name($conn,true) . "\n";
    fwrite($conn,"conn success\n");

};

$server->onMessage = function($conn,$msg){
    echo "onMessage --" . $msg . "\n";
    fwrite($conn,"received ".$msg."\n");
};

$server->onClose = function($conn){
    echo "onClose --" . stream_socket_get_name($conn,true) . "\n";
};

$server->run();