package main

import (
	"bufio"
	"bytes"
	"encoding/binary"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io/ioutil"
	Log "log"
	"net"
	"os"
	"runtime/debug"
	"strings"

	"strconv"
	"time"

	"gopkg.in/mgo.v2"
)

const (
	INFO       = "INFO"
	DEBUG      = "DEBUG"
	WARNING    = "WARING"
	ERROR      = "ERROR"
	LOG_STDOUT = 0
	LOG_FILE   = 1
)

type Message struct {
	Time    string
	Message string
}

type Conf struct {
	ListenPort int    `json:"listen_port"`
	MgoHost    string `json:"mgo_host"`
	MgoPort    int    `json:"mgo_port"`
	LogType    int    `json:"log_type"`
	LogDir     string `json:"log_dir"`
}

type MgoSession struct {
	S *mgo.Session
}

var conf *Conf

func main() {
	confFile := flag.String("c", "/etc/mgologger.json", "configuer file path")
	flag.Parse()
	file, err := os.OpenFile(*confFile, os.O_RDONLY, 0755)
	if err != nil {
		Log.Fatalln(err)
		return
	}
	ct, err := ioutil.ReadAll(file)
	if err != nil {
		Log.Fatalln(err)
		return
	}
	conf = &Conf{}
	err = json.Unmarshal(ct, conf)
	if err != nil {
		Log.Fatalln(err)
		return
	}

	listen, err := net.Listen("tcp", ":"+strconv.Itoa(conf.ListenPort))
	if err != nil {
		Log.Fatalln(err)
		return
	}
	log(INFO, "监听启动成功")

	for {
		conn, err := listen.Accept()
		if err != nil {
			log(ERROR, err.Error())
			return
		}
		go process(&conn)
	}
}

//获取mongod会话
func getMongoDbSession(session *MgoSession, record *map[string]interface{}) error {
	user, ok := (*record)["user"].(string)
	if !ok {
		return errors.New("mongodb用户名获取失败")
	}
	password, ok := (*record)["password"].(string)
	if !ok {
		return errors.New("mongodb用户密码获取失败")
	}
	s, err := mgo.Dial("mongodb://" + user + ":" + password + "@" + conf.MgoHost + ":" + strconv.Itoa(conf.MgoPort) + "/admin")
	if err != nil {
		log(ERROR, err.Error())
		return errors.New("mongo数据库连接失败")
	}
	s.SetMode(mgo.Monotonic, true)
	session.S = s
	return nil
}

//将日志写入mongodb
func mongoAdd(conn *net.Conn, session *MgoSession, record *map[string]interface{}) {
	if session.S == nil {
		serr := getMongoDbSession(session, record)
		if serr != nil {
			response(conn, false, serr.Error())
			return
		}

	}
	if session.S == nil {
		return
	}
	data := *record
	app, _ := data["app"].(string)
	level, _ := data["level"].(string)
	c := session.S.DB(app).C(level)
	var err error
	switch data["log"].(type) {
	case string:
		body, _ := data["log"].(string)
		msg := Message{time.Now().Format("2006-01-02 15:04:05"), body}
		err = c.Insert(msg)
	case map[string]interface{}:
		msg := make(map[string]string)
		for k, v := range data["log"].(map[string]interface{}) {
			msg[k], _ = v.(string)
		}
		if _, ok := msg["time"]; !ok {
			msg["time"] = time.Now().Format("2006-01-02 15:04:05")
		}
		err = c.Insert(msg)
	default:
		err = errors.New("不支持的log参数类型:")
	}

	if err == nil {
		response(conn, true, "")
	} else {
		errMsg := err.Error()
		log(ERROR, errMsg)
		response(conn, false, strings.Replace(errMsg, "\"", "\\\"", -1))
	}
	return
}

//处理请求
func process(conn *net.Conn) {
	defer catchException()
	defer (*conn).Close()
	reader := bufio.NewReader(*conn)
	session := &MgoSession{}
	defer func() {
		if session.S != nil {
			session.S.Close()
		}
	}()

	for {
		var len int32
		binary.Read(reader, binary.LittleEndian, &len)
		if len <= 0 {
			response(conn, true, "")
			break
		}
		data := make([]byte, len)
		_, err := reader.Read(data)
		if err != nil {
			log(ERROR, err.Error())
			response(conn, false, "数据包读取失败")
			break
		}
		record := &map[string]interface{}{}
		err = json.Unmarshal(data, record)

		if err != nil {
			log(ERROR, err.Error()+"\t["+string(data)+"]")
			response(conn, false, "数据包内容不是有效json字符串")
			break
		}

		mongoAdd(conn, session, record)
	}

}

//返回响应
func response(conn *net.Conn, success bool, errormsg string) {
	succ := "false"
	if success {
		succ = "true"
	}
	data := []byte("{\"success\":" + succ + ",\"message\":\"" + errormsg + "\"}")
	buf := new(bytes.Buffer)
	dataLen := uint32(len(data))
	binary.Write(buf, binary.LittleEndian, dataLen)
	binary.Write(buf, binary.LittleEndian, data)
	_, err := (*conn).Write(buf.Bytes())
	if err != nil {
		log(ERROR, err.Error())
	}

}

//捕获异常
func catchException() {
	if p := recover(); p != nil {
		v := fmt.Sprintf("%s", p)
		stack := strings.Replace(string(debug.Stack()), "\n", "", -1)
		log(ERROR, v+"\t["+stack+"]")
	}
}

//记录日志
//默认写入当前目录下log目录
//当log日志创建失败时，日志输出至STDOUT
func log(level string, msg string) {
	now := time.Now()
	nowTime := now.Format("2006-01-02 15:04:05")
	if conf.LogType == LOG_STDOUT {
		fmt.Fprintf(os.Stdout, "%s\t[%s]\t%s\n", nowTime, level, msg)
		return

	}
	dir := conf.LogDir + "/" + now.Format("200601")
	logFile := dir + "/" + strconv.Itoa(now.Day()) + ".log"
	file, err := os.OpenFile(logFile, os.O_CREATE|os.O_RDWR|os.O_APPEND, os.ModeAppend|os.ModePerm)
	if err != nil {
		if os.IsNotExist(err) {
			if _, err := os.Stat(dir); os.IsNotExist(err) {
				if err = os.MkdirAll(dir, 0777); err != nil {
					fmt.Fprintf(os.Stdout, "%s\t[%s]\t%s\n", nowTime, ERROR, err.Error())
				}
			}
			file, err = os.Create(logFile)
			if err != nil {
				fmt.Fprintf(os.Stdout, "%s\t[%s]\t%s\n", nowTime, ERROR, err.Error())
			}
		} else {
			fmt.Fprintf(os.Stdout, "%s\t[%s]\t%s\n", nowTime, ERROR, err.Error())
		}
	}

	if file == nil {
		fmt.Fprintf(os.Stdout, "%s\t[%s]\t%s\n", nowTime, level, msg)
	} else {
		fmt.Fprintf(file, "%s\t[%s]\t%s\n", nowTime, level, msg)
		file.Close()
	}
}
