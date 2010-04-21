var EVHttpRecvCallBack=function(){};
var EVHttpFinCallBack=function(){};
var EVHttpCometUrl=null;
function EVHttpRecv(data){
    EVHttpRecvCallBack();
}
function EVHttpFin(){
    EVHttpFinCallBack();
    $.getScript(EVHttpCometUrl);
}
function EVHttp_setRecvCallback(CallBack){
    EVHttpRecv=CallBack;
}
function EVHttp_setFinCallback(CallBack){
    EVHttpFin=CallBack;
}
function EVHttpComet(Url){
    EVHttpCometUrl=Url;
    $.getScript(Url);
}