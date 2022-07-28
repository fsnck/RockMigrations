// fix language tabs sometimes not having the correct language
$(window).load(function() {
  if(typeof ProcessWire == 'undefined') return;
  if(typeof ProcessWire.config == 'undefined') return;
  if(typeof ProcessWire.config.rmUserLang == 'undefined') return;
  let lang = ProcessWire.config.rmUserLang;
  setTimeout(() => {
    $(".langTab"+lang).click();
    console.log('LanguageTabs set via RockMigrations');
  }, 200);
});
