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

檔案說明
--------
* laws.csv : 法條列表，分別為 代碼,法條名稱,狀態
* laws-category.csv : 法條與主分類和次分類的對照，為 主分類,次分類,狀態,代碼,法條名稱
* laws-versions.csv : 法條的各版本及包含資訊，分別為 代碼,法條名稱,發布時間,包含資訊

備註
----
* 法條分類分為主分類(Ex: 內政、外交僑務...)，以及次分類(Ex: 內政>民政、外交僑務>領務...)，一個法條有可能同時存在於兩個分類(Ex: 財團法人原住民族文化事業基金會設置條例 同時存在於 內政>人民團體 和 原住民族>原民文教)
* 法條名稱也不是唯一，例如 交通建設>交通政務>交通部組織法 有已廢止(ID=02001)和現行法(ID=02080) 兩個版本
License
-------
程式碼以 BSD License 開放授權

