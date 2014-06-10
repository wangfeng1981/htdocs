angular.module('myapp',['ionic'])

.controller('listcontrol',function($scope,$ionicModal){
  $scope.splist = [
  {title:'AppleTV'},
  {title:'iPhone4GS'},
  {title:'Mac OSX'},
  {title:'iPod Touch5'}
  ];

  $ionicModal.fromTemplateUrl('my-modal1.html',
    {scope:$scope,animation:'slide-in-up'})
  .then(function(tModal){
    $scope.modal1 = tModal ;
  });

  $scope.openModal =function(){
    $scope.modal1.show() ;
  };

  $scope.closeModal =function(){
    $scope.modal1.hide() ;
  };

  $scope.add =function(newItem){
    $scope.splist.push({title:newItem.title}) ;
    newItem.title = '';
    $scope.modal1.hide() ;

  };

}) 

.controller('sideControl', function($scope){
  $scope.splist = [
    {title:"Android"},
    {title:"Google Glass"},
    {title:"Samsung Kr"}
  ];



});
