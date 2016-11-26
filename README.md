twlaw
=====

將 http://lis.ly.gov.tw/lglawc/lglawkm 的法律修改歷程資料轉換成 git repository 的程式  
執行成果放在 https://github.com/ronnywang/tw-law-corpus  
或是在 https://github.com/ronnywang/tw-law-corpus/tree/json 可以看到 json 格式

使用方法
--------
* 先執行 php crawl.php 會將法條列表爬下來，列表存在 law.csv ，各法條 HTML 會存在 laws/
* 再執行 php export-to-git.php 會將前面抓下來的 HTML ，在 outputs/ 產生 markdown 格式的修改歷程，在 outputs\_json/ 產生 json 格式的修改歷程
* 在 outputs/ 下執行 git push -f 強制把 markdown 內容推到 git master branch
* 在 outputs\_json/ 下執行 git push origin master:json -f 強制把 json 內容推到 git json branch

License
-------
程式碼以 BSD License 開放授權

