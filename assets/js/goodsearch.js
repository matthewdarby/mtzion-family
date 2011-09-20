function GoGoodSearch_6()  {
       var charityid = 805827;
       var siteURL = "mtzionmb.org";
       var openNewWindow = true;
       var searchtype = document.getElementsByName("goodsearch_searchtype_6");
       var baseurl = "http://www.goodsearch.com/Search.aspx";
       var keywordsbox = document.getElementById("goodsearch_keywords_6");
       if (typeof keywordsbox != "undefined")
       {
               var keywords = escape(keywordsbox.value);
               var url = baseurl + "?Keywords=" + keywords;
               if (charityid > 0)
                       url += "&CharityID=" + charityid;
               if (searchtype[1].checked)
                       url += "&Site=" + escape(siteURL);
               url += "&Partner=goodsearch_syn";
               url += "&typetag=" + escape(siteURL);
               if (openNewWindow)
                       window.open(url,'SearchResults','height=700,width=900,location=1,status=1,toolbar=1,scrollbars=1,resizeable=1');
               else
                       window.location.href = url;
       }
}
function CatchEnter_6(e) {
       var key1 = "13";
       var x = "";
       if (document.all)
       {
               var evnt = window.event;
               x = evnt.keyCode;
       }
       else
               x = e.keyCode;
       if (x == key1)
       {
               document.getElementById("goodsearch_submit_6").click();
               return false;
       }
       else
               return true;
}