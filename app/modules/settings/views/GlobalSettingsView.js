//  global.js
//  Directus 6.0

//  (c) RANGER
//  Directus may be freely distributed under the GNU license.
//  For all details and documentation:
//  http://www.getdirectus.com

define([
  'app',
  'underscore',
  'backbone',
  'core/directus',
  'core/BasePageView',
  'core/widgets/widgets',
  'core/t'
], function(app, _, Backbone, Directus, BasePageView, Widgets, __t) {

  'use strict';

  var Global = BasePageView.extend({
    headerOptions: {
      route: {
        title: __t('settings'),
        breadcrumbs: [{ title: __t('settings'), anchor: '#settings'}]
      },
      className: 'header settings'
    },

    leftToolbar: function() {
      var self = this;
      this.saveWidget = new Widgets.SaveWidget({
        widgetOptions: {
          basicSave: true
        },
        onClick: function(event) {
          var data = {};
          _.each(self.editView, function (view, key) {
            data[key] = view.data();
          });

          var model = self.model;
          var success = function(model, resp) {
            app.settings.reset(resp, {parse: true, grouped: true});
            app.router.go('settings');
          };

          data = model.diff(data);

          if (_.keys(data).length) {
            model.save(data, {success: success, patch: true});
          }
        }
      });

      this.saveWidget.disable();

      return [
        this.saveWidget
      ];
    },

    events: {
      'change select': 'checkDiff',
      'keyup input, textarea': 'checkDiff'
    },

    checkDiff: function(e) {
      this.saveWidget.enable()
    },

    beforeRender: function () {
      var self = this;

      _.each(this.editView, function (view) {
        self.insertView('#page-content', view);
      });
    },

    initialize: function(options) {
      var self = this;
      var index = -1;

      this.editView = {};
      _.each(this.model.attributes, function (attrs, key) {
        var structure = options.structure[key];
        var model = self.model.clone().clear();
        model.set(self.model.get(key));

        // add line break after the first group
        if (++index > 0) {
          structure.unshift({
            id: 'divider',
            ui: 'divider',
            char_length: 255,
            options: {
              title: __t('directus_settings_' + key + '_divider_title')
            }
          }, {parse: true});
        }

        self.editView[key] = new Directus.EditView({model: model, structure: structure});
      });

      this.headerOptions.route.title = this.options.title;
    }
  });

  return Global;
});
