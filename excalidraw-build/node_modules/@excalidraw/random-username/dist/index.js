
'use strict'

if (process.env.NODE_ENV === 'production') {
  module.exports = require('./random-username.cjs.production.min.js')
} else {
  module.exports = require('./random-username.cjs.development.js')
}
